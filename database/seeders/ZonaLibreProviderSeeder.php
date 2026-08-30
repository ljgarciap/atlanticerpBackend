<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-433 (ADR-SCRUM433-445-zona-libre-colon.md sección 2) — el proveedor fijo "Zona Libre de
 * Colón" al que Bodega genera órdenes directamente, sin selector. No existía ningún registro
 * (confirmado por grep antes de implementar, ver ADR). `origin = ORIGIN_INTERNACIONAL` a
 * propósito: aunque la Zona Libre está dentro de Panamá, sus envíos SÍ pasan por
 * aduana/liquidación (ver `PurchaseOrder::statusSequence()` y `LiquidationAgency`, ya usados por
 * SCRUM-210/REQ-147 para "órdenes Zona Libre") — un origin=local saltaría esa etapa por completo,
 * lo cual sería incorrecto acá.
 *
 * Datos de contacto (Ana Torres / +507 6555-9090 / Calle Terranova, Zona Libre de Colón) tomados
 * literalmente del mockup `3C__Bodega_NuevaOrdenZL.html` adjunto en Jira — quedan como placeholder
 * hasta que Luis confirme si son reales (ver nota en el desglose de PM); no bloquean el batch
 * porque el formulario de creación nunca los edita (el proveedor viene fijo desde
 * `compras_settings.zona_libre_provider_id`, no un formulario editable de Proveedores para este
 * caso puntual).
 *
 * `address` (antes `notes`, migrado 2026-07-30 por SCRUM-189 al agregar el campo real de
 * dirección) + `category = zona_libre` — consistente con `Provider::deriveOrigin()`, aunque acá
 * `origin` sigue puesto explícito porque este seeder no pasa por el controller HTTP.
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de `DatabaseSeeder`) — es un dato de negocio real
 * (el proveedor existe y las órdenes lo necesitan), no data demo. Idempotente por
 * create-if-not-exists por nombre, mismo criterio que `CoreUserSeeder`: no pisa el registro si ya
 * existe (un cambio de datos de contacto se aplica a mano después, no reseedeando).
 */
class ZonaLibreProviderSeeder extends Seeder
{
    private const PROVIDER_NAME = 'Zona Libre de Colón';

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
                    'name'         => self::PROVIDER_NAME,
                    'category'     => Provider::CATEGORY_ZONA_LIBRE,
                    'origin'       => Provider::ORIGIN_INTERNACIONAL,
                    'currency'     => 'USD',
                    'contact_name' => 'Ana Torres',
                    'phone'        => '+507 6555-9090',
                    'address'      => 'Calle Terranova, Zona Libre de Colón',
                    'is_active'    => true,
                ]);
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
