<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-243 (REQ-180) — hallazgo repetido de QA (2026-07-18→20): el escenario de aceptación
 * "familia con productos de 2 proveedores distintos genera 2 órdenes separadas" nunca fue
 * verificable en dev.atlanticerp.ai porque ningún seeder asignaba `provider_id` a los productos demo
 * del Catálogo (no existe ningún ProviderSeeder — los proveedores reales de dev se crearon a
 * mano vía UI). Asigna 2 proveedores demo a los 3 productos de la familia "Sala Elegante"
 * (2 de un proveedor, 1 de otro) para dejar el escenario probable end-to-end.
 *
 * Idempotente por diseño: `firstOrCreate` nunca duplica el proveedor si ya existe (incluso si
 * alguien ya creó uno con el mismo nombre a mano), y el UPDATE de producto solo toca filas con
 * `provider_id` NULL — nunca pisa una asignación real ya hecha por un usuario.
 */
class DemoProviderAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'illuminations')->first();
        if ($tenant === null) {
            $this->command->warn('Tenant "illuminations" not found — DemoProviderAssignmentSeeder skipped.');
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
        $providerA = Provider::firstOrCreate(['name' => 'LightCorp'], ['currency' => 'USD']);
        $providerB = Provider::firstOrCreate(['name' => 'Iluminex SA'], ['currency' => 'USD']);

        $assignments = [
            'CAND-SAL-005'  => $providerA->id,
            'PISO-LAMP-030' => $providerB->id,
            'MESA-LAMP-015' => $providerA->id,
        ];

        foreach ($assignments as $reference => $providerId) {
            CatalogProduct::where('reference', $reference)->whereNull('provider_id')
                ->update(['provider_id' => $providerId]);
        }
    }
}
