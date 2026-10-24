<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\CotizacionExtractorContract;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\CotizacionIncrementalStagingLoader;
use App\Modules\Legacy\Services\CotizacionMatcher;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-cotizaciones` (que sigue intacto
 * para carga inicial/reconciliación manual). Mismo patrón que
 * `LegacySyncIncrementalProductosCommand` (leer su docblock primero):
 *
 *  - Usa `CotizacionIncrementalStagingLoader` (hash-compare por cabecera, con el hash de las
 *    líneas plegado por XOR — ver docblock de esa clase, PRESUPUESTOSLIN no tiene llave propia).
 *  - CIRCUIT BREAKER: baseline = filas YA EXISTENTES en legacy.cotizaciones ANTES de esta corrida
 *    (nunca el total extraído de esta misma corrida — mismo bug ya corregido en Productos, no
 *    repetirlo). Sin baseline (carga inicial) nunca se evalúa.
 *  - EL PASO CARO (`CotizacionMatcher::match()`, lookup contra legacy.clientes) corre SOLO sobre
 *    las cabeceras que cambiaron de verdad (`changed_ids` del Loader) — no sobre todo lo
 *    extraído. Mismo criterio que el fix de Productos (Mapper/CatalogProductLoader).
 *  - `sync_runs.origen = 'scheduler'`.
 *
 * PENDIENTE — SNS real para el circuit breaker: mismo motivo que en Productos (sin cliente SNS/
 * permiso IAM en la app todavía), se deja Log::warning() interino.
 */
final class LegacySyncIncrementalCotizacionesCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-cotizaciones
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare)+Matcher y reporta conteos, sin registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-cotizaciones, pensada para el Scheduler';

    public function __construct(
        private readonly CotizacionExtractorContract $extractor,
        private readonly CotizacionIncrementalStagingLoader $stagingLoader,
        private readonly CotizacionMatcher $matcher,
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
            $cotizaciones = $this->extractor->extractCotizaciones();
        } catch (Throwable $e) {
            $this->error("Error al extraer de ICG: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: 0, staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        try {
            return $this->stageAndMatch($cotizaciones, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/matchear cotizaciones: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, extraidos: count($cotizaciones), staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }
    }

    /** @param list<CotizacionRow> $cotizaciones */
    private function stageAndMatch(array $cotizaciones, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $baselineTotal = $isDryRun ? 0 : Cotizacion::query()->count();

        $itemsExtracted = 0;
        $itemPageFetcher = function (int $offset, int $limit) use (&$itemsExtracted): array {
            $page = $this->extractor->extractItemsPage($offset, $limit);
            $itemsExtracted += count($page);

            return $page;
        };

        $stagingResult  = $this->stagingLoader->load($cotizaciones, $itemPageFetcher);
        $totalExtraidos = count($cotizaciones);
        $totalCambiados = $stagingResult['cotizaciones']['nuevos'] + $stagingResult['cotizaciones']['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-cotizaciones — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['cotizaciones', $stagingResult['cotizaciones']['total'], $stagingResult['cotizaciones']['nuevos'], $stagingResult['cotizaciones']['cambiados'], $stagingResult['cotizaciones']['sin_cambios']],
        ]);
        $this->line("Items extraídos (PRESUPUESTOSLIN): {$itemsExtracted} | Items re-stageados (solo cabeceras cambiadas): {$stagingResult['items_staged']}");

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging actualizado, no se evalúa el circuit breaker ni se corre el Matcher.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf('Circuit breaker: %.1f%% de cotizaciones cambiaron (umbral %.0f%%) — NO se corre el Matcher, queda en held_for_review.', $changeRatio * 100, self::CHANGE_THRESHOLD * 100));

            Log::warning('legacy:sync-incremental-cotizaciones — circuit breaker disparado', [
                'entidad' => 'cotizaciones', 'total_extraidos' => $totalExtraidos, 'total_cambiados' => $totalCambiados,
                'change_ratio' => $changeRatio, 'threshold' => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingResult['cotizaciones']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_HELD_FOR_REVIEW);

            return self::SUCCESS;
        }

        $changedIds = $stagingResult['changed_ids'];

        if ($changedIds === []) {
            $this->comment('Ninguna cotización cambió — no se ejecuta el Matcher.');
            $syncRun = $this->recordRun($startedAt, $start, extraidos: $totalExtraidos, staged: $stagingResult['cotizaciones']['total'], mapeados: 0, cargados: 0, excluidos: 0, errores: 0, status: SyncRun::STATUS_SUCCESS);
            $this->alerts->publishDigest($syncRun);

            return self::SUCCESS;
        }

        $matchResult = $this->matcher->match($changedIds);
        $status      = $matchResult['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $totalExtraidos,
            staged: $stagingResult['cotizaciones']['total'],
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
            'entidad'     => 'cotizaciones',
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
