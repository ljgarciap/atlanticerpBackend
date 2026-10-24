<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\PedidoExtractorContract;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\PedidoIncrementalStagingLoader;
use App\Modules\Legacy\Services\PedidoMatcher;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-pedidos`. Mismo patrón exacto
 * que `LegacySyncIncrementalCotizacionesCommand` (leer su docblock primero) — circuit breaker
 * sobre baseline pre-corrida, `PedidoMatcher::match()` corre solo sobre `changed_ids`,
 * `sync_runs.origen = 'scheduler'`.
 */
final class LegacySyncIncrementalPedidosCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-pedidos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare)+Matcher y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-pedidos, pensada para el Scheduler';

    public function __construct(
        private readonly PedidoExtractorContract $extractor,
        private readonly PedidoIncrementalStagingLoader $stagingLoader,
        private readonly PedidoMatcher $matcher,
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
            $pedidos = $this->extractor->extractPedidos();
        } catch (Throwable $e) {
            $this->error("Error al extraer de ICG: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: 0, staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        try {
            return $this->stageAndMatch($pedidos, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear pedidos: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: count($pedidos), staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }
    }

    /** @param list<PedidoRow> $pedidos */
    private function stageAndMatch(array $pedidos, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Pedido::query()->count();

        $itemsExtracted = 0;
        $itemPageFetcher = function (int $offset, int $limit) use (&$itemsExtracted): array {
            $page = $this->extractor->extractItemsPage($offset, $limit);
            $itemsExtracted += count($page);

            return $page;
        };

        $stagingResult  = $this->stagingLoader->load($pedidos, $itemPageFetcher);
        $totalExtraidos = count($pedidos);
        $totalCambiados = $stagingResult['pedidos']['nuevos'] + $stagingResult['pedidos']['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-pedidos — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['pedidos', $stagingResult['pedidos']['total'], $stagingResult['pedidos']['nuevos'], $stagingResult['pedidos']['cambiados'], $stagingResult['pedidos']['sin_cambios']],
        ]);
        $this->line("Items extraídos (PEDVENTALIN): {$itemsExtracted} | Items re-stageados (solo cabeceras cambiadas): {$stagingResult['items_staged']}");

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging actualizado, no se evalúa el circuit breaker ni se corre el Matcher.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf('Circuit breaker: %.1f%% de pedidos cambiaron (umbral %.0f%%) — NO se corre el Matcher, queda en held_for_review.', $changeRatio * 100, self::CHANGE_THRESHOLD * 100));

            Log::warning('legacy:sync-incremental-pedidos — circuit breaker disparado', [
                'entidad' => 'pedidos', 'total_extraidos' => $totalExtraidos, 'total_cambiados' => $totalCambiados,
                'change_ratio' => $changeRatio, 'threshold' => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingResult['pedidos']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_HELD_FOR_REVIEW);

            return self::SUCCESS;
        }

        $changedIds = $stagingResult['changed_ids'];

        if ($changedIds === []) {
            $this->comment('Ningún pedido cambió — no se ejecuta el Matcher.');
            $syncRun = $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingResult['pedidos']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_SUCCESS);
            $this->alerts->publishDigest($syncRun);

            return self::SUCCESS;
        }

        $matchResult = $this->matcher->match($changedIds);
        $status      = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $totalExtraidos,
            staged: $stagingResult['pedidos']['total'],
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

    private function recordRun(
        Carbon $startedAt, float $start, int $extraidos, int $staged, int $mapeados,
        int $cargados, int $excluidos, int $errores, string $status,
    ): SyncRun {
        return SyncRun::query()->create([
            'entidad'     => 'pedidos',
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
