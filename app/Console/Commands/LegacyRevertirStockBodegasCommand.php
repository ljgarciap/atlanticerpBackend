<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MensajeSeguroDeExcepcion;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Legacy\Services\StockBodegaLoader;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * SCRUM-1136 F6 — REVERSA de `legacy:cargar-stock-bodegas`. CAMBIO DESTRUCTIVO (borra filas de `product_warehouse_stock` y líneas del Kardex que la carga creó): requiere
 * confirmación explícita de Luis y, en prod, backup verificado antes (ver `docs/runbooks/icg-stock-a-bodegas.md`).
 *
 * Una carga se identifica sin listas de ids: línea de Kardex tipo `Ajuste` con `referencia_entidad_tipo = 'icg_carga_inicial'`, `saldo_inicial = 0` y texto «… almacén XXX».
 * Solo se revierte lo NO tocado después: la fila de stock existe, su `quantity` sigue igual a `saldo_resultante` y no hay ninguna otra línea de Kardex posterior sobre ese
 * (producto, bodega). Lo demás se reporta y no se toca. Revertir resta de `catalog_products.stock_quantity` el mismo delta que sumó la carga. NO borra los productos inactivos
 * que creó `legacy:crear-articulos-sin-catalogo`.
 *
 * DRY-RUN POR DEFECTO. `--apply` exige `--operador=`; una transacción por almacén con verificación y evento de auditoría resumen.
 */
final class LegacyRevertirStockBodegasCommand extends Command
{
    use MensajeSeguroDeExcepcion;

    /** @var string */
    protected $signature = 'legacy:revertir-stock-bodegas
        {--tenant=atlantic : Slug del tenant}
        {--almacen=* : Código(s) de almacén ICG (vacío = todos los confirmados)}
        {--apply : Borra las filas y líneas de la carga. Sin esta opción es un dry-run que solo lee}
        {--operador= : Quién ejecuta (queda en el evento de auditoría). OBLIGATORIO con --apply}';

    /** @var string */
    protected $description = 'SCRUM-1136 F6 — revierte legacy:cargar-stock-bodegas (solo lo no movido después; dry-run por defecto)';

    public function __construct(private readonly StockBodegaLoader $loader)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && trim((string) $this->option('operador')) === '') {
            $this->error('--operador es obligatorio con --apply: queda en el evento de auditoría.');

            return self::FAILURE;
        }

        $slug = (string) $this->option('tenant');
        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$slug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
        try {
            return $this->ejecutar($slug, $apply);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function ejecutar(string $slug, bool $apply): int
    {
        try {
            $destinos = $this->loader->destinos(array_values(array_map('strval', (array) $this->option('almacen'))));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($destinos === []) {
            $this->warn('No hay equivalencias CONFIRMADAS: nada que revertir.');

            return self::SUCCESS;
        }

        $this->info('legacy:revertir-stock-bodegas — ' . ($apply ? 'APLICAR' : 'DRY-RUN'));
        $fallos = 0;
        $filas = [];
        foreach ($destinos as $codigo => $d) {
            $plan = $this->analizar($codigo, $d['warehouse_id']);
            $filas[] = [$codigo . ' → ' . $d['warehouse'], count($plan['revertir']), $plan['suma'], count($plan['tocadas'])];
            if ($apply && $plan['revertir'] !== []) {
                try {
                    DB::transaction(fn () => $this->aplicar($codigo, $d['warehouse_id'], $plan, $slug));
                } catch (Throwable $e) {
                    $this->error("{$codigo}: ROLLBACK, no se borró nada de ese almacén. " . $this->mensajeSeguro($e));
                    $fallos++;
                }
            }
        }
        $this->table(['Almacén → bodega', 'A revertir (no tocadas)', 'Σ unidades', 'NO se revierten (movidas después)'], $filas);

        if (! $apply) {
            $this->comment('Modo dry-run (por defecto): NO se borró nada. Para aplicar: --apply --operador="<nombre>" (cambio destructivo: confirmación de Luis y backup en prod).');
        }

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{revertir: list<array{mov: int, stock: int, cp: int, qty: int}>, tocadas: list<int>, suma: int} */
    private function analizar(string $codalmacen, int $warehouseId): array
    {
        $movT = (new InventoryMovement())->getTable();
        $stockT = (new ProductWarehouseStock())->getTable();

        $rows = DB::select(
            "SELECT m.id AS mov, m.catalog_product_id AS cp, m.saldo_resultante AS qty, s.id AS stock, s.quantity AS actual,
                    EXISTS (SELECT 1 FROM {$movT} o WHERE o.catalog_product_id = m.catalog_product_id AND o.warehouse_id = m.warehouse_id AND o.id > m.id) AS movida
             FROM {$movT} m
             LEFT JOIN {$stockT} s ON s.catalog_product_id = m.catalog_product_id AND s.warehouse_id = m.warehouse_id
             WHERE m.warehouse_id = ? AND m.tipo = ? AND m.referencia_entidad_tipo = ? AND m.saldo_inicial = 0 AND m.referencia_texto LIKE ?
             ORDER BY m.id",
            [$warehouseId, InventoryMovement::TIPO_AJUSTE, StockBodegaLoader::ENTIDAD_KARDEX, '% almacén ' . $codalmacen],
        );

        $revertir = [];
        $tocadas = [];
        $suma = 0;
        foreach ($rows as $r) {
            if ($r->stock === null || (int) $r->actual !== (int) $r->qty || $r->movida) {
                $tocadas[] = (int) $r->cp;
                continue;
            }
            $revertir[] = ['mov' => (int) $r->mov, 'stock' => (int) $r->stock, 'cp' => (int) $r->cp, 'qty' => (int) $r->qty];
            $suma += (int) $r->qty;
        }

        return ['revertir' => $revertir, 'tocadas' => $tocadas, 'suma' => $suma];
    }

    /** @param array{revertir: list<array{mov: int, stock: int, cp: int, qty: int}>, tocadas: list<int>, suma: int} $plan */
    private function aplicar(string $codalmacen, int $warehouseId, array $plan, string $slug): void
    {
        $movT = (new InventoryMovement())->getTable();
        $stockT = (new ProductWarehouseStock())->getTable();
        $cpT = (new CatalogProduct())->getTable();

        foreach (array_chunk($plan['revertir'], 500) as $pedazo) {
            $stockIds = implode(',', array_column($pedazo, 'stock'));
            $movIds = implode(',', array_column($pedazo, 'mov'));
            $valores = [];
            $bind = [];
            foreach ($pedazo as $r) {
                $valores[] = '(?::bigint, ?::integer)';
                array_push($bind, $r['cp'], $r['qty']);
            }
            $lista = implode(', ', $valores);

            $a = DB::affectingStatement("DELETE FROM {$stockT} WHERE id IN ({$stockIds}) AND warehouse_id = ?", [$warehouseId]);
            $b = DB::affectingStatement("DELETE FROM {$movT} WHERE id IN ({$movIds}) AND warehouse_id = ?", [$warehouseId]);
            if ($a !== count($pedazo) || $b !== count($pedazo)) {
                throw new RuntimeException("se borraron {$a} filas de stock y {$b} líneas de Kardex, esperadas " . count($pedazo) . ' (cambió concurrentemente).');
            }
            DB::update(
                "UPDATE {$cpT} c SET stock_quantity = COALESCE(c.stock_quantity, 0) - v.qty, updated_at = now() FROM (VALUES {$lista}) AS v(cp, qty) WHERE c.id = v.cp",
                $bind,
            );
        }

        AuditLog::query()->create([
            'user_id'     => null,
            'action'      => AuditService::LEGACY_REVERTIR_STOCK_BODEGAS,
            'entity_type' => 'product_warehouse_stock',
            'metadata'    => [
                'operador'      => (string) $this->option('operador'),
                'usuario_so'    => get_current_user(),
                'host'          => (string) gethostname(),
                'entorno'       => (string) config('app.env'),
                'tenant'        => $slug,
                'almacen_icg'   => $codalmacen,
                'warehouse_id'  => $warehouseId,
                'filas_revertidas' => count($plan['revertir']),
                'unidades'      => $plan['suma'],
                'no_revertidas' => count($plan['tocadas']),
            ],
        ]);
        $this->info("{$codalmacen}: revertidas " . count($plan['revertir']) . " filas ({$plan['suma']} u).");
    }
}
