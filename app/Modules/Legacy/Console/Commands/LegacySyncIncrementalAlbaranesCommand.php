<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\AlbaranExtractorContract;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\AlbaranIncrementalStagingLoader;
use App\Modules\Legacy\Services\AlbaranMatcher;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-albaranes` (que sigue intacto
 * para carga inicial/reconciliación manual). Mismo patrón que
 * `LegacySyncIncrementalProductosCommand`:
 *
 *  - AlbaranIncrementalStagingLoader hace hash-compare con fold XOR de items plegado en el hash de
 *    la cabecera (ver su docblock) — detecta cambios de línea sin cambio de cabecera, sin
 *    materializar más de una página de items a la vez.
 *  - CIRCUIT BREAKER: baseline = filas de legacy.albaranes YA EXISTENTES antes de esta corrida
 *    (nunca el total de esta misma corrida — mismo bug ya corregido en Productos, no repetirlo
 *    acá). Sin baseline (carga inicial) nunca se evalúa.
 *  - El AlbaranMatcher (paso caro post-staging) corre SOLO sobre los `changed_albaran_ids` — un
 *    albarán sin cambios ni siquiera se re-matchea.
 *  - `sync_runs.origen = 'scheduler'`.
 *
 * Igual que en Productos, la publicación real a SNS del circuit breaker queda pendiente (sin
 * cliente SNS/permiso IAM en la app hoy) — Log::warning() interino, mismo criterio.
 */
final class LegacySyncIncrementalAlbaranesCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-albaranes
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare)+Matcher y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare de cabecera) de legacy:sync-albaranes, pensada para el Scheduler';

    public function __construct(
        private readonly AlbaranExtractorContract $extractor,
        private readonly AlbaranIncrementalStagingLoader $stagingLoader,
        private readonly AlbaranMatcher $matcher,
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
            $this->error("Error al stagear/matchear albaranes: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: count($albaranes), staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }
    }

    /** @param list<AlbaranRow> $albaranes */
    private function stageAndMatch(array $albaranes, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Albaran::query()->count();

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

        $this->info('legacy:sync-incremental-albaranes — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['albaranes', $stagingCounts['albaranes']['total'], $stagingCounts['albaranes']['nuevos'], $stagingCounts['albaranes']['cambiados'], $stagingCounts['albaranes']['sin_cambios']],
        ]);
        $this->line("Items extraídos: {$itemsExtracted} | Items insertados (solo cabeceras cambiadas): {$stagingCounts['items']}");

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging actualizado, no se evalúa el circuit breaker ni se registra la corrida.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf('Circuit breaker: %.1f%% de albaranes cambiaron (umbral %.0f%%) — held_for_review.', $changeRatio * 100, self::CHANGE_THRESHOLD * 100));

            Log::warning('legacy:sync-incremental-albaranes — circuit breaker disparado', [
                'entidad' => 'albaranes', 'total_extraidos' => $totalExtraidos, 'total_cambiados' => $totalCambiados,
                'change_ratio' => $changeRatio, 'threshold' => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingCounts['albaranes']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_HELD_FOR_REVIEW);

            return self::SUCCESS;
        }

        $changedIds = $stagingCounts['changed_albaran_ids'];

        if ($changedIds === []) {
            $this->comment('Ningún albarán cambió — no se ejecuta el Matcher.');
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
            'entidad'     => 'albaranes',
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
