<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use App\Modules\Legacy\Support\OperatorContext;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-1114 — reversa de UNA corrida de `legacy:sync-incremental-stock` por su id (criterio 12 de la
 * spec). No borra nada: por cada movimiento de kardex que la corrida creó registra un contramovimiento
 * (`Ajuste` con el signo contrario) en la misma bodega y devuelve la línea base del producto a su valor
 * anterior, todo en una transacción por producto. Dry-run por defecto; `--apply --operador="Nombre"`.
 *
 * Un producto con una corrida POSTERIOR (movimiento de sync con id de corrida mayor) NO se revierte: su
 * base y su stock ya incorporan deltas más nuevos y revertir solo la vieja los desajustaría; se reporta
 * para decisión manual. Una corrida ya revertida no se revierte dos veces.
 *
 * Reintentable: la reversa es idempotente POR MOVIMIENTO (si el contramovimiento de ese producto ya existe, se
 * omite), así que repetir `--apply` tras una caída a mitad no revierte dos veces lo ya revertido. La corrida solo
 * se marca `revertida` cuando TODOS sus productos se revirtieron sin error. Exige `activo = false`: con el sync
 * encendido la siguiente corrida reaplicaría el delta mientras ICG conserve el valor (revisión del Senior Reviewer).
 */
final class LegacyRevertStockSyncRunCommand extends Command
{
    /** @var string */
    protected $signature = 'legacy:revert-stock-sync-run
        {run : Id de la corrida (legacy.sync_runs.id, entidad stock)}
        {--tenant=atlantic : Slug del tenant}
        {--apply : Registra los contramovimientos (por defecto solo informa)}
        {--operador= : Nombre de quien lo corre (obligatorio con --apply)}';

    /** @var string */
    protected $description = 'SCRUM-1114 — revierte con contramovimientos una corrida de la sincronización de stock por delta';

    public function __construct(private readonly KardexService $kardex, private readonly LegacySyncAlertService $alerts)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug   = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        $apply    = (bool) $this->option('apply');
        $operador = trim((string) $this->option('operador'));
        if ($apply && $operador === '') {
            $this->error('--apply requiere --operador="Nombre" (queda en auditoría).');

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, (int) $this->argument('run'), $apply, $operador);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, int $runId, bool $apply, string $operador): int
    {
        $lock = Cache::lock("legacy-sync-stock:{$slug}", 7200);
        if (! $lock->get()) {
            $this->error('Ya hay una corrida o reversa de stock en curso para este tenant.');

            return self::FAILURE;
        }

        try {
            return $this->correr($slug, $runId, $apply, $operador);
        } finally {
            $lock->release();
        }
    }

    private function correr(string $slug, int $runId, bool $apply, string $operador): int
    {
        /** @var SyncRun|null $run */
        $run = SyncRun::query()->where('entidad', 'stock')->find($runId);
        if ($run === null) {
            $this->error("No existe una corrida de stock con id {$runId}.");

            return self::FAILURE;
        }
        if (isset(($run->resumen ?? [])['revertida'])) {
            $this->error("La corrida #{$runId} ya fue revertida: " . json_encode($run->resumen['revertida']));

            return self::FAILURE;
        }
        if ($apply && StockSyncSettings::current()->activo) {
            $this->error('Apague la sincronización (legacy.stock_sync_settings.activo = false) antes de revertir: con el sync encendido la próxima corrida reaplicaría el delta mientras ICG conserve el valor.');

            return self::FAILURE;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, InventoryMovement> $movs */
        $movs = InventoryMovement::query()
            ->where('referencia_entidad_tipo', 'legacy.sync_run')
            ->where('referencia_entidad_id', $runId)
            ->orderBy('id')
            ->get();

        if ($movs->isEmpty()) {
            $this->comment("La corrida #{$runId} no creó movimientos de kardex: no hay nada que revertir.");

            return self::SUCCESS;
        }

        $revertibles = [];
        $posteriores = [];
        $yaRevertidos = 0;
        foreach ($movs as $mov) {
            if ($this->yaRevertido($mov, $runId)) {
                $yaRevertidos++;
                continue;
            }
            $hayPosterior = InventoryMovement::query()
                ->where('catalog_product_id', $mov->catalog_product_id)
                ->where('referencia_entidad_tipo', 'legacy.sync_run')
                ->where('referencia_entidad_id', '>', $runId)
                ->exists();
            $hayPosterior ? $posteriores[] = (int) $mov->catalog_product_id : $revertibles[] = $mov;
        }

        $unidades = array_sum(array_map(static fn (InventoryMovement $m): int => (int) $m->cantidad, $revertibles));
        $this->table(['Concepto', 'Valor'], [
            ['Movimientos de la corrida', $movs->count()],
            ['Ya revertidos antes (se omiten)', $yaRevertidos],
            ['Revertibles', count($revertibles)],
            ['Omitidos (el producto tiene una corrida posterior)', count($posteriores)],
            ['Unidades netas que se devolverían', -$unidades],
        ]);
        foreach (array_slice($posteriores, 0, 20) as $id) {
            $this->line("  - catalog_product {$id}: corrida posterior, revisar a mano");
        }

        if (! $apply) {
            $this->comment('Dry-run: no se registró nada. Use --apply --operador="Nombre".');

            return self::SUCCESS;
        }

        $ok = 0;
        $errores = [];
        foreach ($revertibles as $mov) {
            try {
                $hecho = DB::transaction(function () use ($mov, $runId): bool {
                    // Bloquea la fila de la base antes de mover nada y vuelve a comprobar dentro de la transacción
                    // que el contramovimiento no exista (otra reversa concurrente o un reintento).
                    /** @var Producto|null $producto */
                    $producto = Producto::query()->where('catalog_product_id', $mov->catalog_product_id)->lockForUpdate()->first();
                    if ($producto === null || $this->yaRevertido($mov, $runId)) {
                        return false;
                    }

                    $this->kardex->registrar(
                        (int) $mov->catalog_product_id,
                        (int) $mov->warehouse_id,
                        InventoryMovement::TIPO_AJUSTE,
                        -(int) $mov->cantidad,
                        "Reversa de la corrida de sincronización ICG #{$runId}",
                        'legacy.sync_run_reversa',
                        $runId,
                        null,
                    );
                    // La base vuelve a lo que era antes del delta: base_anterior = base_actual - delta.
                    $producto->update(['stock_al4_aplicado' => (int) ($producto->stock_al4_aplicado ?? 0) - (int) $mov->cantidad]);

                    return true;
                });
                $hecho ? $ok++ : $yaRevertidos++;
            } catch (Throwable $e) {
                $errores[] = "catalog_product {$mov->catalog_product_id}: " . LegacySyncAlertService::sanitizar($e->getMessage());
            }
        }

        // Solo se marca revertida si NO quedó nada pendiente: con errores o productos omitidos por una corrida
        // posterior se registra el avance parcial y se puede reintentar (la reversa es idempotente por movimiento).
        $completa = $errores === [] && $posteriores === [];
        $marca    = ['at' => now()->toIso8601String(), 'operador' => $operador, 'revertidos' => $ok + $yaRevertidos, 'omitidos' => count($posteriores), 'errores' => count($errores)];
        $run->update(['resumen' => array_merge($run->resumen ?? [], $completa ? ['revertida' => $marca] : ['reversa_parcial' => $marca])]);

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_REVERT_STOCK_SYNC,
            'entity_type' => 'sync_run',
            'entity_id'   => $runId,
            'metadata'    => OperatorContext::describe($operador) + [
                'entorno' => (string) config('app.env'), 'tenant' => $slug,
                'revertidos' => $ok, 'omitidos_posteriores' => count($posteriores), 'errores' => count($errores), 'completa' => $completa,
            ],
        ]);
        $ctx = OperatorContext::describe($operador);
        $this->alerts->alertEvento('stock', 'Corrida de stock revertida a mano', [
            "Corrida #{$runId}: {$ok} producto(s) revertidos, " . count($posteriores) . ' omitidos por corrida posterior, ' . count($errores) . ' con error' . ($completa ? ' (reversa completa)' : ' (reversa parcial)'),
            OperatorContext::linea($ctx),
        ]);

        foreach (array_slice($errores, 0, 10) as $e) {
            $this->error($e);
        }
        $this->info("Corrida #{$runId}: {$ok} producto(s) revertidos, " . count($posteriores) . ' omitidos por corrida posterior, ' . count($errores) . ' con error.');

        return $errores === [] ? self::SUCCESS : self::FAILURE;
    }

    /** ¿Ya existe el contramovimiento de este movimiento (mismo producto y bodega, misma corrida)? */
    private function yaRevertido(InventoryMovement $mov, int $runId): bool
    {
        return InventoryMovement::query()
            ->where('catalog_product_id', $mov->catalog_product_id)
            ->where('warehouse_id', $mov->warehouse_id)
            ->where('referencia_entidad_tipo', 'legacy.sync_run_reversa')
            ->where('referencia_entidad_id', $runId)
            ->exists();
    }
}
