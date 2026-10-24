<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-845 — data de prueba de Catálogo/Inventario pedida por Gerencia Test para el demo
 * (2026-09-04), sobre las MISMAS 15 referencias que SCRUM-831 (mismo criterio: NO se registra en
 * DatabaseSeeder::run(), se invoca a mano vía tinker en el entorno destino, NUNCA crea productos
 * ni proveedores nuevos — solo completa campos de filas ya existentes). Se invoca vía:
 *
 *   (new \Database\Seeders\Scrum845InventoryTestDataSeeder)->run();
 *
 * Reglas del ticket:
 * - Rotación (RN1): la mayoría MEDIA — acá 11/15 (73%), 2 ALTA + 2 BAJA elegidos al azar entre
 *   las 15 referencias en cada corrida.
 * - Proveedor (RN2): la mayoría "Zona Libre de Colón" — acá 12/15 (80%); los 3 proveedores
 *   internacionales restantes (ALPHA LIGHTING/LEVITON/PUYUAN) se asignan 1 producto cada uno,
 *   elegido al azar, para garantizar que los 4 proveedores del ticket queden usados al menos una
 *   vez sin dejarlo librado a que el azar los toque a todos. Los 4 deben existir YA en el tenant —
 *   este seeder nunca los crea (a diferencia de DemoProviderAssignmentSeeder, que sí crea
 *   proveedores demo); si falta alguno, aborta sin tocar nada.
 * - Stock mínimo = `reorder_point` (RN3): entero aleatorio 5-30 por producto, independiente.
 * - Bodega (RN4): el inventario que "ya fue agregado" para estos productos debe quedar asociado a
 *   Bodega Central. Ese inventario es `catalog_products.stock_quantity` — la fuente real que ya
 *   lee el resto de Inventario/KPIs (ver docblock de `InventoryController::store()`: normalmente
 *   `stock_quantity` se DERIVA como la suma de `product_warehouse_stock`, ambos se mantienen en
 *   sync) — no `product_warehouse_stock` en sí, que es solo el desglose por bodega. SCRUM-831
 *   (mismas 15 referencias, el día anterior) fijó `stock_quantity` directo sin crear la fila de
 *   desglose correspondiente, dejando productos con cantidad > 0 pero sin ninguna bodega asociada
 *   — exactamente el gap que este ticket pide cerrar. Por producto: si NO hay ninguna fila de
 *   `product_warehouse_stock` y `stock_quantity` > 0, se CREA la fila en Bodega Central con esa
 *   cantidad YA existente (nunca un valor inventado); si ya hay filas repartidas en otras bodegas,
 *   se consolidan en la fila de Bodega Central (suma) por la constraint única
 *   `(catalog_product_id, warehouse_id)` — nunca se duplica la fila.
 *
 * Seguro de re-correr: reasigna rotación/proveedor/stock mínimo de las 15 referencias en cada
 * corrida (vuelve a barajar, sigue cumpliendo las mismas reglas de distribución); la reasociación
 * de bodega es idempotente de verdad (una vez consolidado en Bodega Central, correr de nuevo no
 * encuentra filas "others" que mover).
 */
final class Scrum845InventoryTestDataSeeder extends Seeder
{
    /** @var list<string> */
    private const REFERENCES = [
        '00-A368-W1-L1',
        '000.000.A10.L0',
        '000.000.A20.L0',
        '000.000.A30.L0',
        '000.361.000.A0',
        '000.365.000.L0',
        '000.367.000.L0',
        '000.368.000.L0',
        '000.375.000.L0',
        'ML-28012ABK',
        'GE-SOCKET1',
        'GE-SOCKET2',
        'ML-001A-BKBK',
        'ML-001A-WHBK',
        '0535-01',
    ];

    private const ZONA_LIBRE_NAME = 'Zona Libre de Colón';

    /** @var list<string> Los 4 proveedores del ticket — deben existir ya, nunca se crean acá. */
    private const PROVIDER_NAMES = [
        'ALPHA LIGHTING INTERNATIONAL CO LTD.',
        'LEVITON MANUFACTURING CO., INC.',
        'PUYUAN ELECTRONIC ILUMINATION CO. LIMITED',
        'Zona Libre de Colón',
    ];

    private const WAREHOUSE_NAME = 'Bodega Central';

    private const MIN_REORDER_POINT = 5;

    private const MAX_REORDER_POINT = 30;

    public function run(): void
    {
        if (app()->environment(['production', 'testing'])) {
            $this->command?->error('Scrum845InventoryTestDataSeeder: solo corre en dev/qa — abortado en ' . app()->environment() . '.');
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — Scrum845InventoryTestDataSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $this->seed();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function seed(): void
    {
        $products = CatalogProduct::whereIn('reference', self::REFERENCES)->get()->keyBy('reference');
        $missingProducts = array_values(array_diff(self::REFERENCES, $products->keys()->all()));

        if ($missingProducts !== []) {
            $this->command?->error(
                'Scrum845InventoryTestDataSeeder: faltan referencias en catalog_products, abortado sin tocar nada: '
                . implode(', ', $missingProducts)
            );
            return;
        }

        $providers = Provider::whereIn('name', self::PROVIDER_NAMES)->get()->keyBy('name');
        $missingProviders = array_values(array_diff(self::PROVIDER_NAMES, $providers->keys()->all()));

        if ($missingProviders !== []) {
            $this->command?->error(
                'Scrum845InventoryTestDataSeeder: faltan proveedores (el ticket exige que ya existan, nunca se crean acá), abortado sin tocar nada: '
                . implode(', ', $missingProviders)
            );
            return;
        }

        $bodegaCentral = Warehouse::where('name', self::WAREHOUSE_NAME)->first();

        if ($bodegaCentral === null) {
            $this->command?->error('Scrum845InventoryTestDataSeeder: bodega "' . self::WAREHOUSE_NAME . '" no encontrada, abortado sin tocar nada.');
            return;
        }

        // Rotación: 2 ALTA + 2 BAJA elegidos al azar entre las 15, el resto MEDIA (mayoría, RN1).
        $rotationPicks = self::REFERENCES;
        shuffle($rotationPicks);
        $altaRefs = array_slice($rotationPicks, 0, 2);
        $bajaRefs = array_slice($rotationPicks, 2, 2);

        // Proveedor: 1 producto al azar por cada proveedor internacional (garantiza que los 4
        // queden usados), el resto Zona Libre de Colón (mayoría, RN2). Barajado independiente del
        // de rotación arriba para no correlacionar ambas asignaciones.
        $providerPicks = self::REFERENCES;
        shuffle($providerPicks);
        $internationalNames = array_values(array_diff(self::PROVIDER_NAMES, [self::ZONA_LIBRE_NAME]));
        $providerAssignment = [];
        foreach ($internationalNames as $i => $name) {
            $providerAssignment[$providerPicks[$i]] = $name;
        }

        foreach (self::REFERENCES as $reference) {
            $product = $products[$reference];

            $rotation = match (true) {
                in_array($reference, $altaRefs, true) => CatalogProduct::ROTATION_ALTA,
                in_array($reference, $bajaRefs, true) => CatalogProduct::ROTATION_BAJA,
                default => CatalogProduct::ROTATION_MEDIA,
            };

            $providerName = $providerAssignment[$reference] ?? self::ZONA_LIBRE_NAME;

            $product->update([
                'rotation'      => $rotation,
                'provider_id'   => $providers[$providerName]->id,
                'reorder_point' => random_int(self::MIN_REORDER_POINT, self::MAX_REORDER_POINT),
            ]);

            $this->reassignStockToBodegaCentral($product, $bodegaCentral);
        }

        $this->command?->info(
            'Scrum845InventoryTestDataSeeder: 15 referencias actualizadas (rotación/proveedor/stock mínimo) '
            . 'e inventario existente reasociado a "' . self::WAREHOUSE_NAME . '".'
        );
    }

    /**
     * Asocia el inventario YA EXISTENTE (`stock_quantity`) de un producto a Bodega Central. Ver
     * docblock de la clase (RN4) — nunca inventa una cantidad nueva, solo crea/consolida la fila
     * de `product_warehouse_stock` con la cantidad que el producto ya tenía.
     */
    private function reassignStockToBodegaCentral(CatalogProduct $product, Warehouse $bodegaCentral): void
    {
        $stocks = ProductWarehouseStock::where('catalog_product_id', $product->id)->get();

        if ($stocks->isEmpty()) {
            if (($product->stock_quantity ?? 0) > 0) {
                ProductWarehouseStock::create([
                    'catalog_product_id' => $product->id,
                    'warehouse_id'       => $bodegaCentral->id,
                    'quantity'           => $product->stock_quantity,
                ]);
            }

            return;
        }

        $others = $stocks->where('warehouse_id', '!=', $bodegaCentral->id)->values();

        if ($others->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($stocks, $others, $bodegaCentral) {
            $extraQuantity = $others->sum('quantity');
            $centralRow = $stocks->firstWhere('warehouse_id', $bodegaCentral->id);

            if ($centralRow !== null) {
                $centralRow->increment('quantity', $extraQuantity);
                ProductWarehouseStock::whereIn('id', $others->pluck('id'))->delete();
                return;
            }

            /** @var ProductWarehouseStock $first */
            $first = $others->shift();
            $first->update(['warehouse_id' => $bodegaCentral->id, 'quantity' => $extraQuantity]);

            if ($others->isNotEmpty()) {
                ProductWarehouseStock::whereIn('id', $others->pluck('id'))->delete();
            }
        });
    }
}
