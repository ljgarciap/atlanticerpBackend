<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\MappedProducto;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Models\SyncRunDetail;
use App\Modules\Legacy\Services\CatalogProductLoader;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Services\ProductoIncrementalStagingLoader;
use App\Modules\Legacy\Services\ProductoMapper;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-838 — comando incremental NUEVO, separado de `legacy:sync-productos` (que sigue intacto
 * para carga inicial/reconciliación manual vía infra/legacy-sync.sh --force). Pensado para correr
 * agendado (routes/console.php): 2x/día en prod, 1x/semana en test/dev — ver
 * Illumination/migracion-icg-diseno.md, ticket SCRUM-838.
 *
 * Diferencias reales contra el comando full-sync:
 *  - Usa `ProductoIncrementalStagingLoader` (hash-compare vía `source_hash`, ON CONFLICT ... WHERE)
 *    en vez de `ProductoStagingLoader` — filas sin cambios reales no generan ningún write.
 *  - CIRCUIT BREAKER: si el % de filas nuevas+cambiadas sobre el total de filas YA EXISTENTES en
 *    `legacy.productos` ANTES de esta corrida (no sobre el total extraído de esta misma corrida —
 *    bug real encontrado en el primer intento: con esa base, la primerísima corrida siempre da
 *    100% "nuevo" y dispara el breaker sobre datos que nunca fueron un cambio real) supera
 *    self::CHANGE_THRESHOLD, la corrida NO llega a Mapper/CatalogProductLoader — queda registrada
 *    como `held_for_review` y el operador debe correrla a mano (infra/legacy-sync.sh --force) tras
 *    revisar el motivo. Sin baseline previa (carga inicial, `legacy.productos` vacía) el circuit
 *    breaker nunca se evalúa — no hay nada anómalo que comparar todavía. Umbral a confirmar con
 *    datos reales de variabilidad semana a semana en Senior Review (spec del ticket, punto 6).
 *  - `sync_runs.origen = 'scheduler'` (vs. 'manual' del comando full-sync) — permite distinguir el
 *    origen real de cada corrida (criterio de aceptación de SCRUM-838).
 *
 * PENDIENTE — publicación real a SNS `atlanticerp-alerts` cuando dispara el circuit breaker (spec del
 * ticket dice que este comando "dispara su propia alerta"): la app hoy no tiene ningún cliente SNS
 * ni permiso IAM para `sns:Publish` (verificado — no hay precedente en el código, los 12 alarmas de
 * CloudWatch existentes publican a SNS desde el lado de AWS, nunca desde Laravel). Se deja un
 * Log::warning() interino, que sí llega a CloudWatch vía el agente ya existente (mismo log group
 * que laravel.log) — la publicación real a SNS requiere aprobar el permiso IAM nuevo y agregar el
 * SDK client, fuera de lo que se puede verificar/hacer sin esa aprobación. Confirmar en Senior
 * Review antes de dar este punto por cerrado.
 */
final class LegacySyncIncrementalProductosCommand extends Command
{
    private const CHANGE_THRESHOLD = 0.20;

    /** @var string */
    protected $signature = 'legacy:sync-incremental-productos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(hash-compare)+Mapper y reporta conteos, sin cargar a catalog_products ni registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-838 — variante incremental (hash-compare) de legacy:sync-productos, pensada para el Scheduler';

    public function __construct(
        private readonly ProductoExtractorContract $extractor,
        private readonly ProductoIncrementalStagingLoader $stagingLoader,
        private readonly ProductoMapper $mapper,
        private readonly CatalogProductLoader $catalogLoader,
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
            $extracted = $this->extractor->extract();
        } catch (Throwable $e) {
            $this->error("Error al extraer de ICG: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: 0, staged: 0, mapeados: 0, cargados: 0, excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }

        try {
            return $this->stageMapAndLoad($extracted, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error("Error al stagear/mapear/cargar productos: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun(
                    $startedAt, $start,
                    extraidos: count($extracted->productos), staged: 0, mapeados: 0, cargados: 0,
                    excluidos: 0, errores: 1,
                    status: SyncRun::STATUS_FAILED,
                );
            }

            return self::FAILURE;
        }
    }

    private function stageMapAndLoad(ExtractedProductos $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        // Capturado ANTES del upsert — es la base contra la que se mide "cuánto cambió", nunca el
        // total de esta misma corrida (ver docblock de la clase, bug real ya corregido acá).
        $baselineTotal = $isDryRun ? 0 : Producto::query()->count();

        $stagingCounts  = $this->stagingLoader->load($extracted);
        $totalExtraidos = count($extracted->productos);
        $totalCambiados = $stagingCounts['productos']['nuevos'] + $stagingCounts['productos']['cambiados'];
        $changeRatio    = $baselineTotal > 0 ? $totalCambiados / $baselineTotal : 0.0;

        $this->info('legacy:sync-incremental-productos — reporte de staging');
        $this->table(['Etapa', 'Total', 'Nuevos', 'Cambiados', 'Sin cambios'], [
            ['productos', $stagingCounts['productos']['total'], $stagingCounts['productos']['nuevos'], $stagingCounts['productos']['cambiados'], $stagingCounts['productos']['sin_cambios']],
            ['producto_precios', $stagingCounts['precios']['total'], $stagingCounts['precios']['nuevos'], $stagingCounts['precios']['cambiados'], $stagingCounts['precios']['sin_cambios']],
            ['producto_costos', $stagingCounts['costos']['total'], $stagingCounts['costos']['nuevos'], $stagingCounts['costos']['cambiados'], $stagingCounts['costos']['sin_cambios']],
        ]);

        if ($isDryRun) {
            $this->comment('Modo --dry-run: staging (legacy.*) actualizado, no se evalúa el circuit breaker ni se carga a catalog_products.');

            return self::SUCCESS;
        }

        if ($changeRatio > self::CHANGE_THRESHOLD) {
            $this->warn(sprintf(
                'Circuit breaker: %.1f%% de productos cambiaron en esta corrida (umbral %.0f%%) — NO se aplica a catalog_products, queda en held_for_review.',
                $changeRatio * 100,
                self::CHANGE_THRESHOLD * 100,
            ));

            Log::warning('legacy:sync-incremental-productos — circuit breaker disparado', [
                'entidad'        => 'productos',
                'total_extraidos' => $totalExtraidos,
                'total_cambiados' => $totalCambiados,
                'change_ratio'    => $changeRatio,
                'threshold'       => self::CHANGE_THRESHOLD,
            ]);

            $this->recordRun(
                $startedAt, $start,
                extraidos: $totalExtraidos,
                staged: $stagingCounts['productos']['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_HELD_FOR_REVIEW,
            );

            return self::SUCCESS;
        }

        // Solo los artículos con al menos un cambio real (en productos, precios O costos) siguen
        // al Mapper/CatalogProductLoader — un artículo sin cambios en ninguna de las 3 tablas ni
        // siquiera se mapea, es el ahorro real que pide el ticket, no solo el write de staging.
        $codarticulos = $stagingCounts['changed_codarticulos'];

        if ($codarticulos === []) {
            $this->comment('Ningún artículo cambió — no se ejecuta el Mapper/CatalogProductLoader.');

            $syncRun = $this->recordRun(
                $startedAt, $start,
                extraidos: $totalExtraidos,
                staged: $stagingCounts['productos']['total'],
                mapeados: 0, cargados: 0, excluidos: 0, errores: 0,
                status: SyncRun::STATUS_SUCCESS,
            );
            $this->alerts->publishDigest($syncRun);

            return self::SUCCESS;
        }

        $mapped   = $this->mapper->buildPayloads($codarticulos);
        $mapeados = count(array_filter($mapped, static fn (MappedProducto $m): bool => ! $m->isExcluded()));

        $loadResult = $this->catalogLoader->load($mapped);
        $status     = $loadResult['errors'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: $totalExtraidos,
            staged: $stagingCounts['productos']['total'],
            mapeados: $mapeados,
            cargados: $loadResult['loaded'],
            excluidos: $loadResult['excluded'],
            errores: $loadResult['errors'],
            status: $status,
        );

        $this->persistDetails($syncRun, $mapped, $loadResult['details']);

        if ($status === SyncRun::STATUS_SUCCESS) {
            $this->alerts->publishDigest($syncRun);
        }

        $this->info(sprintf('Cargados: %d | Excluidos: %d | Errores: %d', $loadResult['loaded'], $loadResult['excluded'], $loadResult['errors']));

        return self::SUCCESS;
    }

    /**
     * @param list<MappedProducto> $mapped
     * @param list<array{icg_codarticulo: int, reference: string, action: string, reason: string|null}> $loadDetails
     */
    private function persistDetails(SyncRun $syncRun, array $mapped, array $loadDetails): void
    {
        $excludedByMapper = array_values(array_filter($mapped, static fn (MappedProducto $m): bool => $m->isExcluded()));

        $rows = array_map(
            static fn (MappedProducto $m): array => [
                'referencia' => $m->reference,
                'status'     => SyncRunDetail::STATUS_EXCLUDED,
                'motivo'     => $m->excludedReason,
            ],
            $excludedByMapper,
        );

        foreach ($loadDetails as $d) {
            if ($d['action'] !== 'excluded' && $d['action'] !== 'error') {
                continue;
            }

            $rows[] = [
                'referencia' => $d['reference'],
                'status'     => $d['action'] === 'error' ? SyncRunDetail::STATUS_ERROR : SyncRunDetail::STATUS_EXCLUDED,
                'motivo'     => $d['reason'],
            ];
        }

        $this->detailRecorder->record($syncRun, $rows);
    }

    private function recordRun(
        Carbon $startedAt,
        float $start,
        int $extraidos,
        int $staged,
        int $mapeados,
        int $cargados,
        int $excluidos,
        int $errores,
        string $status,
    ): SyncRun {
        return SyncRun::query()->create([
            'entidad'     => 'productos',
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
