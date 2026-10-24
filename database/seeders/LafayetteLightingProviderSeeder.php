<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-970 (épica SCRUM-969 — integración Lafayette Lighting, Bodega Zona Libre). Provider fijo al
 * que se le atribuyen los productos sincronizados desde la MariaDB externa del proveedor
 * (app_lafayette.vista_productos_lighting) — usado como scope de
 * CatalogProduct.factory_reference para deduplicar (ver App\Modules\Integrations\Lafayette).
 *
 * `category = CATEGORY_LOCALES` / `origin = ORIGIN_LOCAL` — Lafayette Lighting opera en Panamá
 * (certificado TLS de su servidor: C=PA), no cruza aduana como sí lo hace Zona Libre de Colón (ver
 * ZonaLibreProviderSeeder, proveedor DISTINTO — no confundir, ambos "Zona Libre" en el nombre
 * refieren a cosas distintas: uno es la bodega física, el otro un proveedor de compras real).
 *
 * Sin datos de contacto/bancarios — no hay dato real todavía, no se inventan (a diferencia del
 * placeholder de ZonaLibreProviderSeeder, que sí venía de un mockup con nota explícita de Luis).
 *
 * Corre en TODOS los entornos (dato de negocio real, no demo). Idempotente por
 * create-if-not-exists por nombre, mismo criterio que ZonaLibreProviderSeeder/CoreUserSeeder — un
 * cambio de datos se aplica a mano después, no reseedeando.
 */
class LafayetteLightingProviderSeeder extends Seeder
{
    private const PROVIDER_NAME = 'Lafayette Lighting';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — LafayetteLightingProviderSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            if (Provider::where('name', self::PROVIDER_NAME)->doesntExist()) {
                Provider::create([
                    'name'      => self::PROVIDER_NAME,
                    'category'  => Provider::CATEGORY_LOCALES,
                    'origin'    => Provider::ORIGIN_LOCAL,
                    'currency'  => 'USD',
                    'is_active' => true,
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
