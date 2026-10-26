<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\ProductoReferenceSwapService;
use App\Modules\Legacy\Support\DryRunAbort;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-799 — corrección de DATA, una sola vez por entorno: para los productos ya migrados desde
 * ICG (`legacy.productos.catalog_product_id` seteado), corrige el cruce dejado por la asignación
 * vieja de `ProductoMapper` (`reference`/`factory_reference` invertidos — ver docblock de
 * `App\Modules\Legacy\Services\ProductoReferenceSwapService` para el diseño completo). NO toca
 * ICG (a diferencia de `legacy:sync-*`, opera enteramente sobre `catalog_products` ya cargado) ni
 * productos creados a mano fuera de este pipeline.
 *
 * `--dry-run` sigue el mismo patrón que `legacy:fuse-clientes`/`legacy:fuse-proveedores`
 * (`DryRunAbort`, ver su docblock) — rollback real, no escribe nada, no registra en
 * `legacy.sync_runs`. Idempotente: correrlo dos veces no re-swapea nada (la segunda corrida
 * encuentra `factory_reference` ya en `null` en todo lo que se corrigió la primera vez).
 */
final class LegacyFixProductosReferenciaCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:fix-productos-referencia
        {--tenant=atlantic : Slug del tenant a procesar}
        {--dry-run : Preview real (rollback) — no escribe nada, no registra en legacy.sync_runs}';

    /** @var string */
    protected $description = 'SCRUM-799 — corrige el cruce reference/factory_reference en productos ya migrados desde ICG';

    public function __construct(private readonly ProductoReferenceSwapService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
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
            return $this->runSwap($isDryRun, $startedAt, $start);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function runSwap(bool $isDryRun, Carbon $startedAt, float $start): int
    {
        try {
            $result = $isDryRun ? $this->swapWithRollback() : $this->service->swap();
        } catch (Throwable $e) {
            $this->error("Error al corregir referencias: {$e->getMessage()}");

            if (! $isDryRun) {
                $this->recordRun($startedAt, $start, candidatos: 0, corregidos: 0, excluidos: 0, errores: 1, status: SyncRun::STATUS_FAILED);
            }

            return self::FAILURE;
        }

        $this->info('legacy:fix-productos-referencia — reporte');
        $this->table(['Etapa', 'Conteo'], [
            ['Candidatos (legacy.productos con catalog_product_id)', $result['candidatos']],
            ['Corregidos (swap aplicado)', $result['corregidos']],
            ['Sin cambios (nada que corregir)', $result['sin_cambios']],
            ['Excluidos (colisión — revisar a mano)', $result['excluidos']],
            ['Errores', $result['errores']],
        ]);

        $paraRevisar = array_values(array_filter(
            $result['details'],
            static fn (array $d): bool => $d['action'] === 'excluded' || $d['action'] === 'error',
        ));

        if ($paraRevisar !== []) {
            $this->newLine();
            $this->warn('Filas que necesitan revisión manual:');
            $this->table(
                ['icg_codarticulo', 'catalog_product_id', 'action', 'reason'],
                array_map(
                    static fn (array $d): array => [$d['icg_codarticulo'], $d['catalog_product_id'] ?? '—', $d['action'], $d['reason'] ?? '—'],
                    $paraRevisar,
                ),
            );
        }

        if ($isDryRun) {
            $this->newLine();
            $this->comment('Modo --dry-run: rollback real — NO se escribió nada, NO se registró la corrida en legacy.sync_runs.');

            return self::SUCCESS;
        }

        $status = $result['errores'] > 0 ? SyncRun::STATUS_PARTIAL : SyncRun::STATUS_SUCCESS;
        $this->recordRun(
            $startedAt,
            $start,
            candidatos: $result['candidatos'],
            corregidos: $result['corregidos'],
            excluidos: $result['excluidos'],
            errores: $result['errores'],
            status: $status,
        );

        return self::SUCCESS;
    }

    /**
     * Corre `service->swap()` dentro de una `DB::transaction()` externa y fuerza rollback al
     * final — cada fila corregida ya abre su propia transacción (se anida vía savepoints sobre la
     * misma conexión), envolverla acá revierte también esas escrituras internas. Mismo patrón que
     * `LegacyFuseClientesCommand::fuseWithRollback()`.
     *
     * @return array{candidatos: int, corregidos: int, sin_cambios: int, excluidos: int, errores: int, details: list<array<string, mixed>>}
     */
    private function swapWithRollback(): array
    {
        $result = null;

        try {
            DB::transaction(function () use (&$result): void {
                $result = $this->service->swap();

                throw new DryRunAbort();
            });
        } catch (DryRunAbort) {
            // esperado — fuerza el rollback, $result ya quedó capturado por referencia.
        }

        /** @var array{candidatos: int, corregidos: int, sin_cambios: int, excluidos: int, errores: int, details: list<array<string, mixed>>} $result */
        return $result;
    }

    private function recordRun(Carbon $startedAt, float $start, int $candidatos, int $corregidos, int $excluidos, int $errores, string $status): void
    {
        SyncRun::query()->create([
            'entidad'     => 'productos_referencia_swap',
            'extraidos'   => $candidatos,
            'staged'      => $candidatos,
            'mapeados'    => $corregidos,
            'cargados'    => $corregidos,
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
