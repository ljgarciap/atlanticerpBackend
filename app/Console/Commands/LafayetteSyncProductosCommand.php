<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Support\MemoryLimit;
use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Bodega\Services\ZonaLibreCatalogoService;
use App\Modules\Integrations\Lafayette\Contracts\ProductoExtractorContract;
use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;
use App\Modules\Integrations\Lafayette\Data\MappedBatch;
use App\Modules\Integrations\Lafayette\Exceptions\FilaRechazadaException;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use App\Modules\Integrations\Lafayette\Services\LafayetteProductoLoader;
use App\Modules\Integrations\Lafayette\Services\ProductoMapper;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SCRUM-971 / SCRUM-990 (épica SCRUM-969 — integración Lafayette Lighting). Pipeline Extractor → Mapper → Loader.
 *
 * SCRUM-990: escribe ÚNICAMENTE en `bodega.lafayette_productos` (espejo de solo consulta, aislado del catálogo de AtlanticERP).
 * Ya no toca `catalog_products`, `product_warehouse_stock` ni `warehouses`, y ya no asigna proveedor ni "vincula" productos.
 *
 * Agendado en routes/console.php con cadencia por ambiente: producción 2x/día (12:00 y 22:00 hora Panamá), dev 1x/semana
 * (sábado 22:00), test 1x/semana (domingo 22:00).
 *
 * MODO `--dry-run`: corre Extractor + Mapper y reporta conteos (extraídos y únicos), pero NUNCA escribe en la tabla ni
 * registra la corrida en `lafayette_sync_runs`.
 */
final class LafayetteSyncProductosCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'lafayette:sync-productos
        {--tenant=atlantic : Slug del tenant a sincronizar}
        {--dry-run : Corre Extractor+Mapper y reporta conteos, sin escribir en lafayette_productos ni registrar la corrida}';

    /** @var string */
    protected $description = 'SCRUM-990 — extrae vista_productos_lighting de Lafayette y la refleja en bodega.lafayette_productos (Zona Libre, solo consulta)';

    public function __construct(
        private readonly ProductoExtractorContract $extractor,
        private readonly ProductoMapper $mapper,
        private readonly LafayetteProductoLoader $loader,
        private readonly AuditService $auditService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // 25.8K productos en la fuente: mismo resguardo preventivo que LegacySyncProductosCommand (incidente de OOM de SCRUM-918).
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
        $breaker = new CircuitBreaker('lafayette_lighting');

        try {
            $extracted = $breaker->call(fn (): ExtractedProductos => $this->extractor->extract());
        } catch (CircuitOpenException $e) {
            $this->error('Circuito abierto para Lafayette — demasiados fallos recientes, se salta esta corrida: ' . $this->mensajeSeguro($e));
            $this->recordFailure($isDryRun, $startedAt, $start, 0);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Error al extraer de Lafayette: ' . $this->mensajeSeguro($e));
            $this->recordFailure($isDryRun, $startedAt, $start, 0);

            return self::FAILURE;
        }

        try {
            return $this->mapAndLoad($extracted, $isDryRun, $startedAt, $start);
        } catch (Throwable $e) {
            $this->error('Error al mapear/cargar productos: ' . $this->mensajeSeguro($e));
            $this->recordFailure($isDryRun, $startedAt, $start, count($extracted->productos));

            return self::FAILURE;
        }
    }

    private function mapAndLoad(ExtractedProductos $extracted, bool $isDryRun, Carbon $startedAt, float $start): int
    {
        $batch = $this->mapper->buildBatch($extracted);
        $mapped = $batch->productos;

        if ($isDryRun) {
            $this->renderDryRunReport($extracted, $batch);
            $this->newLine();
            $this->comment('Modo --dry-run: NO se escribió en lafayette_productos ni se registró la corrida en lafayette_sync_runs.');

            return self::SUCCESS;
        }

        $result = $this->loader->load($mapped);

        // Filas en cuarentena: las que rechazó el mapper (identificador inválido...) + las que rechazó la BD en el respaldo fila por fila.
        $rechazadas = $batch->rechazadas + $result['rechazadas'];
        $motivos = $batch->motivos;
        if ($result['rechazadas'] > 0) {
            $motivos[FilaRechazadaException::RECHAZADA_POR_BD] = ($motivos[FilaRechazadaException::RECHAZADA_POR_BD] ?? 0) + $result['rechazadas'];
        }

        // `partial` solo si la guarda se activó o si la fracción de filas rechazadas indica que la fuente cambió de contrato o está corrupta.
        $extraidos = count($extracted->productos);
        $demasiadasRechazadas = $extraidos > 0 && ($rechazadas / $extraidos) > (float) config('lafayette.filas_rechazadas_max_ratio', 0.01);
        $status = ($result['guarda_activada'] || $demasiadasRechazadas) ? LafayetteSyncRun::STATUS_PARTIAL : LafayetteSyncRun::STATUS_SUCCESS;

        $this->renderReport($extracted, $result, $rechazadas, $motivos, $demasiadasRechazadas);

        LafayetteSyncRun::query()->create([
            'extraidos'       => count($extracted->productos),
            'creados'         => $result['insertados'],
            'actualizados'    => $result['actualizados'],
            'sin_cambios'     => $result['sin_cambios'],
            'fuera_de_fuente' => $result['fuera_de_fuente'],
            'vinculados'      => 0,
            'coexistentes'    => 0,
            'excluidos'       => 0,
            'errores'         => $rechazadas, // filas en cuarentena (no abortan la corrida); una falla fatal registra 1 en recordFailure()
            'duracion_ms'     => (int) round((microtime(true) - $start) * 1000),
            'status'          => $status,
            'started_at'      => $startedAt,
            'finished_at'     => now(),
            'created_at'      => now(),
        ]);

        // El resumen cacheado de Zona Libre (categorías, totales) cambió: que la próxima lectura lo recalcule.
        ZonaLibreCatalogoService::forgetResumen();

        $this->auditService->log(
            action: AuditService::BODEGA_LAFAYETTE_SYNC_COMPLETED,
            entityType: 'lafayette_sync_run',
            metadata: [
                'extraidos'       => count($extracted->productos),
                'unicos'          => $result['unicos'],
                'insertados'      => $result['insertados'],
                'actualizados'    => $result['actualizados'],
                'sin_cambios'     => $result['sin_cambios'],
                'fuera_de_fuente' => $result['fuera_de_fuente'],
                'guarda_activada' => $result['guarda_activada'],
                'rechazadas'      => $rechazadas,
                'motivos'         => $motivos,
            ],
        );

        return self::SUCCESS;
    }

    private function renderDryRunReport(ExtractedProductos $extracted, MappedBatch $batch): void
    {
        $unique = [];
        foreach ($batch->productos as $item) {
            $unique[$item->lafayetteId] = true;
        }

        $this->info('lafayette:sync-productos — reporte (dry-run)');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (vista_productos_lighting)', count($extracted->productos)],
            ['Rechazadas por el mapper (cuarentena)', $batch->rechazadas],
            ['Únicos por id de Lafayette', count($unique)],
        ]);
        $this->renderMotivos($batch->motivos);
    }

    /**
     * @param array{unicos: int, insertados: int, actualizados: int, sin_cambios: int, fuera_de_fuente: int, rechazadas: int, guarda_activada: bool} $result
     * @param array<string, int> $motivos
     */
    private function renderReport(ExtractedProductos $extracted, array $result, int $rechazadas, array $motivos, bool $demasiadasRechazadas): void
    {
        $this->info('lafayette:sync-productos — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Extraídos (vista_productos_lighting)', count($extracted->productos)],
            ['Rechazadas (cuarentena: se omiten y se cuentan)', $rechazadas],
            ['Únicos por id de Lafayette', $result['unicos']],
            ['Insertados', $result['insertados']],
            ['Actualizados', $result['actualizados']],
            ['Sin cambios (no se escriben)', $result['sin_cambios']],
            ['Marcados fuera de la fuente', $result['fuera_de_fuente']],
        ]);

        $this->renderMotivos($motivos);
        if ($demasiadasRechazadas) {
            $this->warn('Demasiadas filas rechazadas respecto de las extraídas: la fuente pudo cambiar de contrato o estar corrupta (corrida registrada como "partial").');
        }

        if ($result['guarda_activada']) {
            $this->warn(
                'GUARDA: la extracción vino vacía o por debajo de la fracción mínima de la corrida previa; '
                . 'no se marcó ningún producto como fuera de la fuente (corrida registrada como "partial").'
            );
        }
    }

    /** @param array<string, int> $motivos código de motivo => cantidad (nunca contenido de las filas) */
    private function renderMotivos(array $motivos): void
    {
        if ($motivos === []) {
            return;
        }

        $this->line('<comment>Motivos de rechazo</comment>');
        $this->table(['Motivo', 'Filas'], array_map(static fn (string $m, int $n): array => [$m, $n], array_keys($motivos), array_values($motivos)));
    }

    private function recordFailure(bool $isDryRun, Carbon $startedAt, float $start, int $extraidos): void
    {
        if ($isDryRun) {
            return;
        }

        LafayetteSyncRun::query()->create([
            'extraidos'       => $extraidos,
            'creados'         => 0,
            'actualizados'    => 0,
            'sin_cambios'     => 0,
            'fuera_de_fuente' => 0,
            'vinculados'      => 0,
            'coexistentes'    => 0,
            'excluidos'       => 0,
            'errores'         => 1,
            'duracion_ms'     => (int) round((microtime(true) - $start) * 1000),
            'status'          => LafayetteSyncRun::STATUS_FAILED,
            'started_at'      => $startedAt,
            'finished_at'     => now(),
            'created_at'      => now(),
        ]);
    }
}
