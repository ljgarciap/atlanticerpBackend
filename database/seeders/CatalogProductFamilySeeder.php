<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\Support\DemoDataset;
use Illuminate\Database\Seeder;

/**
 * REQ-037: familias demo del Catálogo — necesarias para poder probar el tab
 * "Familias" del buscador (REQ-037) en dev.atlanticerp.ai. Igual criterio que
 * CatalogProductSeeder: sin pantalla de administración todavía, se siembra a
 * mano; corre después de CatalogProductSeeder (necesita los productos ya
 * creados para armar el pivot). Datos demo, no la lista real del cliente —
 * decisión explícita de Luis (2026-07-13, daily) para poder probar el flujo
 * end-to-end antes de tener la curaduría real.
 *
 * SCRUM-715: ampliado de 3 a 10 familias, en línea con el catálogo ampliado
 * de CatalogProductSeeder (46 productos). Las 3 familias originales se
 * mantienen con su membresía original y suman los productos nuevos de la
 * misma categoría.
 */
class CatalogProductFamilySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — CatalogProductFamilySeeder skipped.');
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
        if (CatalogProductFamily::exists()) {
            return;
        }

        $families = [
            DemoDataset::familia(1) => [
                DemoDataset::productoRef(5), DemoDataset::productoRef(3), DemoDataset::productoRef(4),
                DemoDataset::productoRef(11), DemoDataset::productoRef(12), DemoDataset::productoRef(13), DemoDataset::productoRef(14), DemoDataset::productoRef(15),
            ],
            DemoDataset::familia(2) => [
                DemoDataset::productoRef(7), DemoDataset::productoRef(9), DemoDataset::productoRef(10),
                DemoDataset::productoRef(16), DemoDataset::productoRef(17), DemoDataset::productoRef(18),
            ],
            DemoDataset::familia(3) => [
                DemoDataset::productoRef(3), DemoDataset::productoRef(8), DemoDataset::productoRef(6),
                DemoDataset::productoRef(19), DemoDataset::productoRef(20), DemoDataset::productoRef(21),
            ],
            DemoDataset::familia(4) => [
                DemoDataset::productoRef(22), DemoDataset::productoRef(23), DemoDataset::productoRef(24), DemoDataset::productoRef(25), DemoDataset::productoRef(26),
            ],
            DemoDataset::familia(5) => [
                DemoDataset::productoRef(1), DemoDataset::productoRef(2), DemoDataset::productoRef(27), DemoDataset::productoRef(28), DemoDataset::productoRef(29), DemoDataset::productoRef(30),
            ],
            DemoDataset::familia(6) => [
                DemoDataset::productoRef(3), DemoDataset::productoRef(4), DemoDataset::productoRef(31), DemoDataset::productoRef(32), DemoDataset::productoRef(33), DemoDataset::productoRef(34),
            ],
            DemoDataset::familia(7) => [
                DemoDataset::productoRef(35), DemoDataset::productoRef(36), DemoDataset::productoRef(37),
            ],
            DemoDataset::familia(8) => [
                DemoDataset::productoRef(38), DemoDataset::productoRef(39), DemoDataset::productoRef(40),
            ],
            DemoDataset::familia(9) => [
                DemoDataset::productoRef(41), DemoDataset::productoRef(42), DemoDataset::productoRef(43),
            ],
            DemoDataset::familia(10) => [
                DemoDataset::productoRef(44), DemoDataset::productoRef(45), DemoDataset::productoRef(46),
            ],
        ];

        foreach ($families as $name => $references) {
            $family = CatalogProductFamily::create(['name' => $name, 'is_active' => true]);

            $productIds = CatalogProduct::whereIn('reference', $references)->pluck('id', 'reference');
            foreach ($references as $reference) {
                if (! $productIds->has($reference)) {
                    continue;
                }
                $family->products()->attach($productIds[$reference]);
            }
        }
    }
}
