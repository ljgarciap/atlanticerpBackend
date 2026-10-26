<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\AlbaranCompraExtractorContract;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\AlbaranCompraIncrementalStagingLoader;
use App\Modules\Legacy\Services\AlbaranCompraMatcher;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — equivalente de compras a `LegacySyncIncrementalAlbaranesCommand`. Mismo patrón:
 * circuit breaker con baseline pre-corrida, Matcher solo sobre `changed_albaran_ids`,
 * `sync_runs.origen = 'scheduler'`.
 */
final class LegacySyncIncrementalAlbaranesCompraCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-albaranes-compra
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare)+Matcher y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare de cabecera) de legacy:sync-albaranes-compra, pensada para el Scheduler';

    public function __construct(
        private readonly AlbaranCompraExtractorContract $extractor,
        private readonly AlbaranCompraIncrementalStagingLoader $stagingLoader,
        private readonly AlbaranCompraMatcher $matcher,
        private readonly SyncRunDetailRecorder $detailRecorder,
        private readonly LegacySyncAlertService $alerts,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        MemoryLimit::atLeast('512M');

        $tenantSlug = (string) $this->option('tenant');
        $isDryRun   = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        $startedAt = now();
        $start     = microtime(true);

        $tenant->makeCurrent();
        try {
            return $this->runPipeline($isDryRun, $startedAt, $start);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runPipeline(bool $isDryRun, Carbon $startedAt, float $start): int
    {
        try {
            $albaranes = $this->extractor->extractAlbaranes();
        } catch (Throwable $e) {
            $this->error("Error al extraer de ICG: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: 0, staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        try {
            return $this->stageAndMatch($albaranes, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear albaranes de compra: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: count($albaranes), staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }
    }

    /** @param list<AlbaranCompraRow> $albaranes */
    private function stageAndMatch(array $albaranes, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : AlbaranCompra::query()->count();

        $itemsExtracted  = 0;
        $itemPageFetcher = function (int $offset, int $limit) use (&$itemsExtracted): array {
            $page = $this->extractor->extractItemsPage($offset, $limit);
            $itemsExtracted += count($page);

            return $page;
        };

        $stagingCounts  = $this->stagingLoader->loadPaginated($albaranes, $itemPageFetcher);
        $totalExtraidos = count($albaranes);
        $totalCambiados = $stagingCounts['albaranes']['nuevos'] + $stagingCounts['albaranes']['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-albaranes-compra — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['albaranes_compra', $stagingCounts['albaranes']['total'], $stagingCounts['albaranes']['nuevos'], $stagingCounts['albaranes']['cambiados'], $stagingCounts['albaranes']['sin_cambios']],
        ]);
        $this->line("Items extraídos: {$itemsExtracted} | Items insertados: {$stagingCounts['items']}");

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging actualizado, no se evalúa el circuit breaker ni se registra la corrida.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf('Circuit breaker: %.1f%% de albaranes de compra cambiaron (umbral %.0f%%) — held_for_review.', $changeRatio * 100, self::CHANGE_THRESHOLD * 100));

            Log::warning('legacy:sync-incremental-albaranes-compra — circuit breaker disparado', [
                'entidad' => 'albaranes_compra', 'total_extraidos' => $totalExtraidos, 'total_cambiados' => $totalCambiados,
                'change_ratio' => $changeRatio, 'threshold' => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingCounts['albaranes']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_HELD_FOR_REVIEW);

            return self::SUCCESS;
        }

        $changedIds = $stagingCounts['changed_albaran_ids'];

        if ($changedIds === []) {
            $this->comment('Ningún albarán de compra cambió — no se ejecuta el Matcher.');
            $syncRun = $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingCounts['albaranes']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_SUCCESS);
            $this->alerts->publishDigest($syncRun);

            return self::SUCCESS;
        }

        $matchResult = $this->matcher->match($changedIds);
        $status      = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $totalExtraidos,
            staged: $stagingCounts['albaranes']['total'],
            mapeados: $matchResult['matched'],
            cargados: $matchResult['matched'],
            excluidos: $matchResult['orphan'],
            errores: $matchResult['errores'],
            status: $status,
        );

        $this->detailRecorder->record($syncRun, $matchResult['details']);

        if ($status === SyncRun::STATUS_SUCCESS) {
            $this->alerts->publishDigest($syncRun);
        }

        $this->info(sprintf('Matched: %d | Orphan: %d | Errores: %d', $matchResult['matched'], $matchResult['orphan'], $matchResult['errores']));

        return self::SUCCESS;
    }

    private function recordRun(Carbon $startedAt, float $start, int $extraidos, int $staged, int $mapeados, int $cargados, int $excluidos, int $errores, string $status): SyncRun
    {
        return SyncRun::query()->create([
            'entidad'     => 'albaranes_compra',
            'origen'      => SyncRun::ORIGEN_SCHEDULER,
            'extraidos'   => $extraidos,
            'staged'      => $staged,
            'mapeados'    => $mapeados,
            'cargados'    => $cargados,
            'excluidos'   => $excluidos,
            'errores'     => $errores,
            'duracion_ms' => (int) round((microtime(true) - $start) * 1000),
            'status'      => $status,
            'started_at'  => $startedAt,
            'finished_at' => now(),
            'created_at'  => now(),
        ]);
    }
}
