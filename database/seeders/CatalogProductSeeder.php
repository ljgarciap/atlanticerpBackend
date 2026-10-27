<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\Support\DemoDataset;
use Illuminate\Database\Seeder;

/**
 * REQ-036: productos demo del Catálogo — necesarios para poder probar el
 * buscador (REQ-036) y las fórmulas de Tipo de Precio sobre un producto real
 * (REQ-033/035/042) en dev.atlanticerp.ai. Mismo criterio que VentasDisenoDemoSeeder:
 * nunca en 'testing' (los tests de feature crean sus propios CatalogProduct
 * directo, sin depender de datos compartidos).
 *
 * SCRUM-715: ampliado de 10 a 46 productos cubriendo 10 familias (los 10
 * originales se mantienen sin cambios — Scrum711SalesForceDemoSeeder ya
 * referencia catálogo por índice genérico via CatalogProduct::pluck('id'),
 * nunca por `reference` puntual, así que sumar productos no rompe cotizaciones
 * ya sembradas por SCRUM-714/716).
 */
class CatalogProductSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — CatalogProductSeeder skipped.');
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
        if (CatalogProduct::exists()) {
            return;
        }

        $products = [
            ['reference' => DemoDataset::productoRef(1), 'description' => DemoDataset::productoDescripcion(1), 'brand' => DemoDataset::marca('A'), 'price_full' => 189.99, 'cost' => 95.00, 'stock_quantity' => 24],
            ['reference' => DemoDataset::productoRef(2), 'description' => DemoDataset::productoDescripcion(2), 'brand' => DemoDataset::marca('A'), 'price_full' => 149.50, 'cost' => 78.00, 'stock_quantity' => 15],
            ['reference' => DemoDataset::productoRef(3), 'description' => DemoDataset::productoDescripcion(3), 'brand' => DemoDataset::marca('B'), 'price_full' => 24.99, 'cost' => 11.50, 'stock_quantity' => 300],
            ['reference' => DemoDataset::productoRef(4), 'description' => DemoDataset::productoDescripcion(4), 'brand' => DemoDataset::marca('B'), 'price_full' => 24.99, 'cost' => 11.50, 'stock_quantity' => 210],
            ['reference' => DemoDataset::productoRef(5), 'description' => DemoDataset::productoDescripcion(5), 'brand' => DemoDataset::marca('B'), 'price_full' => 65.00, 'cost' => 32.00, 'stock_quantity' => 40],
            ['reference' => DemoDataset::productoRef(6), 'description' => DemoDataset::productoDescripcion(6), 'brand' => DemoDataset::marca('A'), 'price_full' => 45.75, 'cost' => 21.00, 'stock_quantity' => 60],
            ['reference' => DemoDataset::productoRef(7), 'description' => DemoDataset::productoDescripcion(7), 'brand' => DemoDataset::marca('C'), 'price_full' => 890.00, 'cost' => 410.00, 'stock_quantity' => 3],
            ['reference' => DemoDataset::productoRef(8), 'description' => DemoDataset::productoDescripcion(8), 'brand' => DemoDataset::marca('B'), 'price_full' => 32.00, 'cost' => 14.00, 'stock_quantity' => 150],
            ['reference' => DemoDataset::productoRef(9), 'description' => DemoDataset::productoDescripcion(9), 'brand' => DemoDataset::marca('C'), 'price_full' => 320.00, 'cost' => 165.00, 'stock_quantity' => 8],
            ['reference' => DemoDataset::productoRef(10), 'description' => DemoDataset::productoDescripcion(10), 'brand' => DemoDataset::marca('A'), 'price_full' => 79.90, 'cost' => 38.00, 'stock_quantity' => 45],

            // SCRUM-715 — familia demo 01 (ampliación)
            ['reference' => DemoDataset::productoRef(11), 'description' => DemoDataset::productoDescripcion(11), 'brand' => DemoDataset::marca('B'), 'price_full' => 68.00, 'cost' => 34.00, 'stock_quantity' => 35],
            ['reference' => DemoDataset::productoRef(12), 'description' => DemoDataset::productoDescripcion(12), 'brand' => DemoDataset::marca('B'), 'price_full' => 92.00, 'cost' => 46.00, 'stock_quantity' => 20],
            ['reference' => DemoDataset::productoRef(13), 'description' => DemoDataset::productoDescripcion(13), 'brand' => DemoDataset::marca('B'), 'price_full' => 28.50, 'cost' => 13.50, 'stock_quantity' => 180],
            ['reference' => DemoDataset::productoRef(14), 'description' => DemoDataset::productoDescripcion(14), 'brand' => DemoDataset::marca('B'), 'price_full' => 28.50, 'cost' => 13.50, 'stock_quantity' => 160],
            ['reference' => DemoDataset::productoRef(15), 'description' => DemoDataset::productoDescripcion(15), 'brand' => DemoDataset::marca('B'), 'price_full' => 9.90, 'cost' => 4.20, 'stock_quantity' => 90],

            // SCRUM-715 — familia demo 02 (ampliación)
            ['reference' => DemoDataset::productoRef(16), 'description' => DemoDataset::productoDescripcion(16), 'brand' => DemoDataset::marca('C'), 'price_full' => 650.00, 'cost' => 305.00, 'stock_quantity' => 5],
            ['reference' => DemoDataset::productoRef(17), 'description' => DemoDataset::productoDescripcion(17), 'brand' => DemoDataset::marca('A'), 'price_full' => 145.00, 'cost' => 68.00, 'stock_quantity' => 18],
            ['reference' => DemoDataset::productoRef(18), 'description' => DemoDataset::productoDescripcion(18), 'brand' => DemoDataset::marca('A'), 'price_full' => 95.50, 'cost' => 44.00, 'stock_quantity' => 22],

            // SCRUM-715 — familia demo 03 (ampliación)
            ['reference' => DemoDataset::productoRef(19), 'description' => DemoDataset::productoDescripcion(19), 'brand' => DemoDataset::marca('B'), 'price_full' => 38.00, 'cost' => 17.50, 'stock_quantity' => 120],
            ['reference' => DemoDataset::productoRef(20), 'description' => DemoDataset::productoDescripcion(20), 'brand' => DemoDataset::marca('B'), 'price_full' => 42.00, 'cost' => 19.00, 'stock_quantity' => 95],
            ['reference' => DemoDataset::productoRef(21), 'description' => DemoDataset::productoDescripcion(21), 'brand' => DemoDataset::marca('B'), 'price_full' => 4.50, 'cost' => 1.80, 'stock_quantity' => 500],

            // SCRUM-715 — familia demo 04 (familia nueva)
            ['reference' => DemoDataset::productoRef(22), 'description' => DemoDataset::productoDescripcion(22), 'brand' => DemoDataset::marca('D'), 'price_full' => 58.00, 'cost' => 27.00, 'stock_quantity' => 40],
            ['reference' => DemoDataset::productoRef(23), 'description' => DemoDataset::productoDescripcion(23), 'brand' => DemoDataset::marca('D'), 'price_full' => 34.90, 'cost' => 15.50, 'stock_quantity' => 70],
            ['reference' => DemoDataset::productoRef(24), 'description' => DemoDataset::productoDescripcion(24), 'brand' => DemoDataset::marca('D'), 'price_full' => 22.00, 'cost' => 9.50, 'stock_quantity' => 60],
            ['reference' => DemoDataset::productoRef(25), 'description' => DemoDataset::productoDescripcion(25), 'brand' => DemoDataset::marca('B'), 'price_full' => 39.00, 'cost' => 17.00, 'stock_quantity' => 55],
            ['reference' => DemoDataset::productoRef(26), 'description' => DemoDataset::productoDescripcion(26), 'brand' => DemoDataset::marca('D'), 'price_full' => 46.50, 'cost' => 21.00, 'stock_quantity' => 38],

            // SCRUM-715 — familia demo 05 (familia nueva)
            ['reference' => DemoDataset::productoRef(27), 'description' => DemoDataset::productoDescripcion(27), 'brand' => DemoDataset::marca('E'), 'price_full' => 165.00, 'cost' => 78.00, 'stock_quantity' => 25],
            ['reference' => DemoDataset::productoRef(28), 'description' => DemoDataset::productoDescripcion(28), 'brand' => DemoDataset::marca('E'), 'price_full' => 210.00, 'cost' => 98.00, 'stock_quantity' => 12],
            ['reference' => DemoDataset::productoRef(29), 'description' => DemoDataset::productoDescripcion(29), 'brand' => DemoDataset::marca('E'), 'price_full' => 128.00, 'cost' => 60.00, 'stock_quantity' => 20],
            ['reference' => DemoDataset::productoRef(30), 'description' => DemoDataset::productoDescripcion(30), 'brand' => DemoDataset::marca('E'), 'price_full' => 285.00, 'cost' => 132.00, 'stock_quantity' => 9],

            // SCRUM-715 — familia demo 06 (familia nueva)
            ['reference' => DemoDataset::productoRef(31), 'description' => DemoDataset::productoDescripcion(31), 'brand' => DemoDataset::marca('B'), 'price_full' => 19.90, 'cost' => 8.90, 'stock_quantity' => 260],
            ['reference' => DemoDataset::productoRef(32), 'description' => DemoDataset::productoDescripcion(32), 'brand' => DemoDataset::marca('B'), 'price_full' => 21.50, 'cost' => 9.50, 'stock_quantity' => 220],
            ['reference' => DemoDataset::productoRef(33), 'description' => DemoDataset::productoDescripcion(33), 'brand' => DemoDataset::marca('B'), 'price_full' => 32.00, 'cost' => 14.50, 'stock_quantity' => 90],
            ['reference' => DemoDataset::productoRef(34), 'description' => DemoDataset::productoDescripcion(34), 'brand' => DemoDataset::marca('D'), 'price_full' => 27.00, 'cost' => 12.00, 'stock_quantity' => 65],

            // SCRUM-715 — familia demo 07 (familia nueva)
            ['reference' => DemoDataset::productoRef(35), 'description' => DemoDataset::productoDescripcion(35), 'brand' => DemoDataset::marca('B'), 'price_full' => 54.00, 'cost' => 24.50, 'stock_quantity' => 45],
            ['reference' => DemoDataset::productoRef(36), 'description' => DemoDataset::productoDescripcion(36), 'brand' => DemoDataset::marca('A'), 'price_full' => 62.00, 'cost' => 28.00, 'stock_quantity' => 30],
            ['reference' => DemoDataset::productoRef(37), 'description' => DemoDataset::productoDescripcion(37), 'brand' => DemoDataset::marca('A'), 'price_full' => 36.50, 'cost' => 16.50, 'stock_quantity' => 55],

            // SCRUM-715 — familia demo 08 (familia nueva)
            ['reference' => DemoDataset::productoRef(38), 'description' => DemoDataset::productoDescripcion(38), 'brand' => DemoDataset::marca('B'), 'price_full' => 22.00, 'cost' => 9.80, 'stock_quantity' => 80],
            ['reference' => DemoDataset::productoRef(39), 'description' => DemoDataset::productoDescripcion(39), 'brand' => DemoDataset::marca('B'), 'price_full' => 32.00, 'cost' => 14.50, 'stock_quantity' => 60],
            ['reference' => DemoDataset::productoRef(40), 'description' => DemoDataset::productoDescripcion(40), 'brand' => DemoDataset::marca('A'), 'price_full' => 175.00, 'cost' => 82.00, 'stock_quantity' => 15],

            // SCRUM-715 — familia demo 09 (familia nueva)
            ['reference' => DemoDataset::productoRef(41), 'description' => DemoDataset::productoDescripcion(41), 'brand' => DemoDataset::marca('D'), 'price_full' => 55.00, 'cost' => 25.00, 'stock_quantity' => 70],
            ['reference' => DemoDataset::productoRef(42), 'description' => DemoDataset::productoDescripcion(42), 'brand' => DemoDataset::marca('D'), 'price_full' => 110.00, 'cost' => 52.00, 'stock_quantity' => 25],
            ['reference' => DemoDataset::productoRef(43), 'description' => DemoDataset::productoDescripcion(43), 'brand' => DemoDataset::marca('D'), 'price_full' => 48.00, 'cost' => 22.00, 'stock_quantity' => 40],

            // SCRUM-715 — familia demo 10 (familia nueva)
            ['reference' => DemoDataset::productoRef(44), 'description' => DemoDataset::productoDescripcion(44), 'brand' => DemoDataset::marca('E'), 'price_full' => 6.90, 'cost' => 2.80, 'stock_quantity' => 200],
            ['reference' => DemoDataset::productoRef(45), 'description' => DemoDataset::productoDescripcion(45), 'brand' => DemoDataset::marca('E'), 'price_full' => 58.00, 'cost' => 26.00, 'stock_quantity' => 35],
            ['reference' => DemoDataset::productoRef(46), 'description' => DemoDataset::productoDescripcion(46), 'brand' => DemoDataset::marca('E'), 'price_full' => 44.00, 'cost' => 20.00, 'stock_quantity' => 28],
        ];

        foreach ($products as $product) {
            // SCRUM-237/240 — `name` es ahora NOT NULL (ver migración 2026_08_15_100000_...);
            // el texto que estos 46 arrays ya traían en `description` siempre fue en realidad el
            // nombre visible del producto (título corto, ej. "Producto demo 01"), no una descripción
            // larga aparte — se mapea a `name` en vez de duplicarlo
            // en las 2 columnas, `description` queda vacía como en cualquier alta real hoy.
            $product['name'] = $product['description'];
            unset($product['description']);
            CatalogProduct::create($product);
        }
    }
}
