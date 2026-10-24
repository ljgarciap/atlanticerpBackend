<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-1135 (antes SCRUM-433) — proveedor fijo para órdenes de compra de Zona Libre.
 * Cambiado de "Zona Libre de Colón" a "BK INTERPRISES S.A." por solicitud de negocio.
 *
 * `origin = ORIGIN_INTERNACIONAL`: sus envíos pasan por aduana/liquidación
 * (ver `PurchaseOrder::statusSequence()` y `LiquidationAgency`).
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de `DatabaseSeeder`). Idempotente por
 * create-if-not-exists por nombre; si el setting ya apunta al proveedor correcto, no lo toca.
 */
class ZonaLibreProviderSeeder extends Seeder
{
    private const PROVIDER_NAME = 'BK INTERPRISES S.A.';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — ZonaLibreProviderSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $provider = Provider::where('name', self::PROVIDER_NAME)->first();

            if ($provider === null) {
                $provider = Provider::create([
                    'name'     => self::PROVIDER_NAME,
                    'category' => Provider::CATEGORY_ZONA_LIBRE,
                    'origin'   => Provider::ORIGIN_INTERNACIONAL,
                    'currency' => 'USD',
                    'is_active' => true,
                ]);
            }

            // Si BK ya existía (alta manual previa, sin categoría), se completa solo la categoría vacía;
            // nunca se pisa una categoría ya definida.
            if ($provider->category === null || $provider->category === '') {
                $provider->update(['category' => Provider::CATEGORY_ZONA_LIBRE]);
            }

            $settings = ComprasSettings::current();
            if ($settings->zona_libre_provider_id !== $provider->id) {
                $settings->update(['zona_libre_provider_id' => $provider->id]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
