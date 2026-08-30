<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
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
            'Riel Direccionable' => [
                'RIEL-TRK-050', 'SPOT-EMP-010', 'SPOT-EMP-011',
                'RIEL-TRK-051', 'RIEL-TRK-052', 'SPOT-TRK-012', 'SPOT-TRK-013', 'CONE-TRK-060',
            ],
            'Sala Elegante' => [
                'CAND-SAL-005', 'PISO-LAMP-030', 'MESA-LAMP-015',
                'CAND-SAL-006', 'PISO-LAMP-031', 'MESA-LAMP-016',
            ],
            'Iluminación LED Básica' => [
                'SPOT-EMP-010', 'CINT-LED-100', 'APLQ-PAR-020',
                'PANEL-LED-070', 'TIRA-LED-101', 'BOMB-LED-200',
            ],
            'Iluminación Exterior' => [
                'FARO-EXT-300', 'REFL-EXT-301', 'POSTE-EXT-302', 'CINT-EXT-303', 'APLQ-EXT-304',
            ],
            'Colgantes de Diseño' => [
                'LAMP-COL-001', 'LAMP-COL-002', 'LAMP-COL-003', 'LAMP-COL-004', 'LAMP-COL-005', 'LAMP-COL-006',
            ],
            'Empotrables & Downlights' => [
                'SPOT-EMP-010', 'SPOT-EMP-011', 'DOWN-EMP-400', 'DOWN-EMP-401', 'DOWN-EMP-402', 'OJOBUEY-EMP-403',
            ],
            'Baño & Espejo' => [
                'ESP-BAN-500', 'APLQ-BAN-501', 'TECHO-BAN-502',
            ],
            'Cocina & Bajo Alacena' => [
                'BARRA-COC-600', 'BARRA-COC-601', 'COLG-COC-602',
            ],
            'Oficina & Comercial' => [
                'PANEL-OFI-700', 'RIEL-OFI-701', 'SPOT-OFI-702',
            ],
            'Decorativo Vintage/Industrial' => [
                'BOMB-VIN-800', 'LAMP-VIN-801', 'APLQ-VIN-802',
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
