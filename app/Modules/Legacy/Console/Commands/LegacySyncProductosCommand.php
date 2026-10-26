<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Modules\Legacy\Contracts\ProductoExtractorContract;
use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\MappedProducto;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Models\SyncRunDetail;
use App\Modules\Legacy\Services\CatalogProductLoader;
use App\Modules\Legacy\Services\ProductoMapper;
use App\Modules\Legacy\Services\ProductoStagingLoader;
use App\Modules\Legacy\Services\SyncRunDetailRecorder;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-750 (épica SCRUM-749 — Migración ICG→AtlanticERP), Tareas 1-5 — piloto de Catálogo
 * (Illumination/migracion-icg-diseno.md §6.1). Pipeline completo Extractor→Loader-A(staging)→
 * Mapper→Loader-B(catalog_products) para el batch de Productos.
 *
 * MODO `--dry-run`: corre Extractor + staging (Loader-A) + Mapper, reporta conteos —
 * extraídos/staged/mapeados/excluidos con motivo — pero NUNCA escribe en
 * `ventas_diseno.catalog_products` ni registra la corrida en `legacy.sync_runs`. El staging SÍ se
 * escribe en dry-run (es upsert idempotente sobre tablas de solo-lectura de referencia, correrlo
 * en modo rehearsal es seguro y es justamente el punto de tener una capa de staging separada del
 * destino real).
 *
 * DECISIÓN DE DISEÑO — dry-run NO registra en `legacy.sync_runs`: la tabla es "una fila por
 * corrida REAL" (ver docblock de la migración) — una corrida de prueba no debería contar para el
 * monitoreo de "más de 10 días sin corrida exitosa" (§11 vacío #5 del diseño). Alternativa
 * descartada: registrar con `status='partial'` — se prefirió no ensuciar la tabla con corridas que
 * nunca tocaron el destino real.
 *
 * La Tarea 6 (corrida real contra el gateway VPN / dev.atlanticerp.ai / test.atlanticerp.ai) es explícitamente
 * FUERA de este batch — este comando nunca se corrió contra SQL Server real todavía.
 */
final class LegacySyncProductosCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:sync-productos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+staging(Loader-A)+Mapper y reporta conteos, sin cargar a catalog_products ni registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-750 — extrae ARTICULOS/PRECIOSVENTA/PRECIOSCOMPRA de ICG, stagea en legacy.* y mapea/carga a ventas_diseno.catalog_products';

    public function __construct(
        private readonly ProductoExtractorContract $extractor,
        private readonly ProductoStagingLoader $stagingLoader,
        private readonly ProductoMapper $mapper,
        private readonly CatalogProductLoader $catalogLoader,
        private readonly SyncRunDetailRecorder $detailRecorder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Descubierto 2026-08-12 (SCRUM-750, Tarea 6, primera corrida real contra ICG): el volumen
        // real de artículos activos revienta el memory_limit default de PHP CLI (128M) — tanto en
        // la extracción (arrays/DTOs en memoria) como en el Mapper (Collections de Eloquent).
        // Mismo criterio ya usado en `infra/test.sh` para los PDFs de Compras/Bodega (ver su
        // docblock) — acá SÍ alcanza con `ini_set()` porque este comando corre en el proceso PHP
        // que ya arrancó (no spawnea un hijo separado como `artisan test`), así que el override
        // aplica directo sin necesitar el flag `-d` en la invocación.
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

        // Hallazgo Pre-QA SCRUM-750 (2026-08-12): antes, una excepción acá (ej. staging/mapper
        // por una fila con dato fuera de rango, como una descripción > 255 chars) escapaba sin
        // capturar — el comando terminaba con un stack trace crudo y, peor, la corrida NUNCA
        // quedaba registrada en `legacy.sync_runs` (el `try/catch` original solo cubría la
        // extracción), dejando el vacío de monitoreo #5 del diseño (§11) sin resolver para
        // cualquier falla que no sea de conexión. Mismo patrón que el catch de arriba — siempre
        // dejar rastro de una corrida REAL, incluso si falla acá.
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
        $stagingCounts = $this->stagingLoader->load($extracted);
        $codarticulos  = $extracted->icgCodarticulos();
        $mapped        = $this->mapper->buildPayloads($codarticulos);
        $mapeados      = count(array_filter($mapped, static fn (MappedProducto $m): bool => ! $m->isExcluded()));

        if ($isDryRun) {
            $this->renderReport($extracted, $stagingCounts, $mapped, cargados: 0, loadDetails: []);
            $this->newLine();
            $this->comment(
                'Modo --dry-run: staging (legacy.*) actualizado, pero NO se escribió en '
                . 'catalog_products ni se registró la corrida en legacy.sync_runs.'
            );

            return self::SUCCESS;
        }

        $loadResult = $this->catalogLoader->load($mapped);

        $this->renderReport($extracted, $stagingCounts, $mapped, $loadResult['loaded'], $loadResult['details']);

        $status = $loadResult['errors'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;

        $syncRun = $this->recordRun(
            $startedAt, $start,
            extraidos: count($extracted->productos),
            staged: $stagingCounts['productos'],
            mapeados: $mapeados,
            cargados: $loadResult['loaded'],
            excluidos: $loadResult['excluded'],
            errores: $loadResult['errors'],
            status: $status,
        );

        $this->persistDetails($syncRun, $mapped, $loadResult['details']);

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

    /**
     * @param array{productos: int, precios: int, costos: int} $stagingCounts
     * @param list<MappedProducto> $mapped
     * @param list<array{icg_codarticulo: int, reference: string, action: string, reason: string|null}> $loadDetails
     */
    private function renderReport(
        ExtractedProductos $extracted,
        array $stagingCounts,
        array $mapped,
        int $cargados,
        array $loadDetails,
    ): void {
        $excludedByMapper = array_values(array_filter($mapped, static fn (MappedProducto $m): bool => $m->isExcluded()));
        $mapeados         = count($mapped) - count($excludedByMapper);

        $this->info('legacy:sync-productos — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (ARTICULOS activos)', count($extracted->productos)],
            ['Staged — productos', $stagingCounts['productos']],
            ['Staged — precios', $stagingCounts['precios']],
            ['Staged — costos', $stagingCounts['costos']],
            ['Mapeados (listos para catalog_products)', $mapeados],
            ['Excluidos por Mapper', count($excludedByMapper)],
            ['Cargados en catalog_products', $cargados],
        ]);

        if ($excludedByMapper !== []) {
            $this->newLine();
            $this->line('<comment>Excluidos por Mapper — motivo</comment>');
            $this->table(
                ['icg_codarticulo', 'reference', 'motivo'],
                array_map(
                    static fn (MappedProducto $m): array => [$m->icgCodarticulo, $m->reference, $m->excludedReason],
                    $excludedByMapper,
                ),
            );
        }

        $loaderExclusionsAndErrors = array_values(array_filter(
            $loadDetails,
            static fn (array $d): bool => $d['action'] === 'excluded' || $d['action'] === 'error',
        ));

        if ($loaderExclusionsAndErrors !== []) {
            $this->newLine();
            $this->line('<comment>Excluidos/errores en Loader-B (catalog_products) — motivo</comment>');
            $this->table(
                ['icg_codarticulo', 'reference', 'acción', 'motivo'],
                array_map(
                    static fn (array $d): array => [$d['icg_codarticulo'], $d['reference'], $d['action'], $d['reason']],
                    $loaderExclusionsAndErrors,
                ),
            );
        }
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
