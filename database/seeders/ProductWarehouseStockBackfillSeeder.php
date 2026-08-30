<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-422 (REQ-352) — hallazgo de QA 2026-07-24 (marly.rangel): la columna "Bodega(s)" de
 * Inventario y su modal de desglose mostraban 0/vacío para productos con stock real
 * (`catalog_products.stock_quantity` > 0). Investigación de causa raíz (Pre-QA/Backend Dev
 * 2026-07-27/28): NO es un bug de query — tanto
 * `BodegaInventoryController::warehouseCounts()` como `warehouseRows()` (el desglose que consume
 * `warehouseBreakdown()`) ya filtran correctamente `quantity > 0`. Confirmado con una query real
 * contra Postgres local: `illuminations_bodega.product_warehouse_stock` tenía CERO filas — nunca
 * hubo un seeder que distribuyera el `stock_quantity` de `CatalogProductSeeder` entre las bodegas
 * físicas reales (`WarehouseAssistantMappingSeeder`/Tipo A crea las 7 bodegas, pero ningún Tipo B
 * las llenaba). El caso "SPOT-EMP-010 repartido en 2 bodegas" que Pre-QA validó en vivo el
 * 2026-07-23 se sembró a mano por tinker en esa sesión — no sobrevivió a un reseed posterior del
 * entorno (mismo patrón de "verificado en navegador ≠ persistente" ya documentado en memoria del
 * proyecto).
 *
 * Seeder propio (no dentro de `CatalogProductSeeder`) porque ese seeder es no-idempotente por
 * completo (`if (CatalogProduct::exists()) return;` — nunca vuelve a correr su cuerpo una vez que
 * ya hay productos, como ya pasa en dev.atlanticerp.ai), así que agregar la distribución ahí no
 * backfillearía nada en un entorno ya sembrado. Este seeder SÍ es idempotente por fusión
 * (`updateOrCreate` sobre la unique key real `catalog_product_id`+`warehouse_id`) y corre en cada
 * deploy, sea la primera vez o no.
 *
 * Mismo gating que el resto de datos demo (Tipo B, `database/seeders/DatabaseSeeder.php`): nunca
 * en `qa` (test.atlanticerp.ai) ni `production`.
 */
class ProductWarehouseStockBackfillSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            // La suite de Feature tests siembra su propio ProductWarehouseStock por test
            // (`BodegaOrderTestHelpers::setStock()`) — no depende de datos compartidos.
            return;
        }

        $tenant = Tenant::where('slug', 'illuminations')->first();
        if ($tenant === null) {
            $this->command?->warn('Tenant "illuminations" not found — ProductWarehouseStockBackfillSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * Bodega donde cae por default cualquier producto con stock que no tenga una distribución
     * explícita abajo — ver SCRUM-717.
     */
    private const DEFAULT_WAREHOUSE = 'Bodega Central';

    private function seed(): void
    {
        // reference => [warehouse_name => quantity, ...]. Suma siempre igual a
        // `catalog_products.stock_quantity` del producto — nunca inventa cantidades que no
        // existan en el dato maestro. SPOT-EMP-010 queda repartido en 2 bodegas a propósito, para
        // preservar el escenario de aceptación (RN1/Escenario 1: "producto con stock repartido en
        // 2 bodegas") que Pre-QA ya validó en vivo el 2026-07-23. Solo lista acá los productos que
        // necesitan MÁS de una bodega o una bodega distinta del default — cualquier otro producto
        // con stock lo resuelve el paso 2 (backfill genérico) sin tener que enumerarlo.
        $distribution = [
            'LAMP-COL-001' => ['Bodega Central' => 24],
            'LAMP-COL-002' => ['Bodega Central' => 15],
            'SPOT-EMP-010' => ['Bodega Central' => 200, 'Showroom Obarrio' => 100],
            'SPOT-EMP-011' => ['Bodega Central' => 210],
            'RIEL-TRK-050' => ['Bodega Central' => 40],
            'APLQ-PAR-020' => ['Bodega Central' => 60],
            'CAND-SAL-005' => ['Bodega Central' => 3],
            'CINT-LED-100' => ['Bodega Central' => 150],
            'PISO-LAMP-030' => ['Bodega Central' => 8],
            'MESA-LAMP-015' => ['Bodega Central' => 45],
        ];

        /** @var array<string, int> $warehouseIdByName */
        $warehouseIdByName = Warehouse::pluck('id', 'name')->all();

        foreach ($distribution as $reference => $warehouses) {
            $product = CatalogProduct::where('reference', $reference)->first();
            if ($product === null) {
                continue;
            }

            foreach ($warehouses as $warehouseName => $quantity) {
                $warehouseId = $warehouseIdByName[$warehouseName] ?? null;
                if ($warehouseId === null) {
                    continue;
                }

                ProductWarehouseStock::updateOrCreate(
                    ['catalog_product_id' => $product->id, 'warehouse_id' => $warehouseId],
                    ['quantity' => $quantity],
                );
            }
        }

        // SCRUM-717 (2026-07-30) — 39 de 82 productos del catálogo (48%) mostraban
        // Disponible/Stock Total > 0 pero "0 bodegas": la lista de arriba solo cubría los 10 SKU
        // originales, y cada ampliación de catálogo posterior (SCRUM-715 y cualquier producto
        // creado a mano vía "+ Producto nuevo" en Compras) quedaba sin distribuir porque nadie
        // volvía a tocar este archivo — el mismo patrón de lista hardcodeada que se queda atrás ya
        // encontrado y corregido en `DemoCleanupController`. En vez de enumerar los 39 SKU (que se
        // volvería a romper con el próximo producto nuevo), cualquier producto con
        // `stock_quantity > 0` que siga sin ninguna fila en `product_warehouse_stock` después del
        // paso de arriba cae completo en la bodega default — nunca inventa una cantidad que no
        // esté ya en el dato maestro.
        $defaultWarehouseId = $warehouseIdByName[self::DEFAULT_WAREHOUSE] ?? null;
        if ($defaultWarehouseId === null) {
            return;
        }

        $distributedProductIds = ProductWarehouseStock::query()->distinct()->pluck('catalog_product_id');

        $undistributed = CatalogProduct::query()
            ->where('stock_quantity', '>', 0)
            ->whereNotIn('id', $distributedProductIds)
            ->get(['id', 'stock_quantity']);

        foreach ($undistributed as $product) {
            ProductWarehouseStock::updateOrCreate(
                ['catalog_product_id' => $product->id, 'warehouse_id' => $defaultWarehouseId],
                ['quantity' => $product->stock_quantity],
            );
        }
    }
}
