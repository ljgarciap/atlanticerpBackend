<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\ReplacementRequest;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-246→249 — hallazgo repetido de QA (5 revisiones entre 2026-07-18 y 2026-07-20): en
 * dev.atlanticerp.ai no existía ninguna solicitud de reemplazo, y el único camino de la app para
 * crearlas (Buscar sustituto, SCRUM-245/247) estaba a su vez bloqueado en QA — el escenario
 * "filtrando por Rechazadas, todas las filas muestran Generar orden" nunca fue verificable.
 *
 * Siembra 3 productos demo (original + sustituto con margen sobre el umbral + sustituto con
 * margen bajo el umbral de compras_settings.replacement_margin_threshold, para poder ejercitar
 * los 2 escenarios de la regla de margen de SCRUM-247) y una solicitud en cada uno de los 4
 * estados. Original y sustitutos tienen nombres y costos claramente distintos, como pidió QA,
 * para poder verificar sin ambigüedad cuál se precarga en "Generar orden" (SCRUM-249). El
 * original queda con proveedor asignado — sin eso, "Generar orden" responde 422 a propósito.
 *
 * Idempotente por fusión: firstOrCreate en productos, proveedor y solicitudes — nunca duplica
 * ni pisa datos existentes.
 */
class DemoReplacementRequestSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $tenant = Tenant::where('slug', 'illuminations')->first();
        if ($tenant === null) {
            $this->command->warn('Tenant "illuminations" not found — DemoReplacementRequestSeeder skipped.');
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
        $provider = Provider::firstOrCreate(['name' => 'LightCorp'], ['currency' => 'USD']);

        $original = CatalogProduct::firstOrCreate(
            ['reference' => 'SUST-ORIG-001'],
            [
                'name'           => 'Bombillo decorativo E27 Ámbar (demo sustitutos — original)',
                'brand'          => 'Demo',
                'price_full'     => 24.00,
                'cost'           => 12.00,
                'stock_quantity' => 3,
                'is_active'      => true,
            ],
        );
        if ($original->provider_id === null) {
            $original->update(['provider_id' => $provider->id]);
        }

        // Margen 60% — sobre el umbral (15% default): la solicitud nace "Pendiente" sin escalar.
        $goodMargin = CatalogProduct::firstOrCreate(
            ['reference' => 'SUST-CAND-002'],
            [
                'name'           => 'Bombillo LED E27 Blanco cálido (demo sustitutos — margen alto)',
                'brand'          => 'Demo',
                'price_full'     => 25.00,
                'cost'           => 10.00,
                'stock_quantity' => 14,
                'is_active'      => true,
            ],
        );

        // Margen 12% — bajo el umbral: la solicitud debe nacer "Pendiente Gerencia" (SCRUM-247).
        $lowMargin = CatalogProduct::firstOrCreate(
            ['reference' => 'SUST-CAND-003'],
            [
                'name'           => 'Bombillo halógeno E27 Regulable (demo sustitutos — margen bajo)',
                'brand'          => 'Demo',
                'price_full'     => 25.00,
                'cost'           => 22.00,
                'stock_quantity' => 8,
                'is_active'      => true,
            ],
        );

        $requests = [
            [ReplacementRequest::STATUS_PENDIENTE, $goodMargin->id, 60.0],
            [ReplacementRequest::STATUS_PENDIENTE_GERENCIA, $lowMargin->id, 12.0],
            [ReplacementRequest::STATUS_APROBADA, $goodMargin->id, 60.0],
            [ReplacementRequest::STATUS_RECHAZADA, $lowMargin->id, 12.0],
        ];

        foreach ($requests as [$status, $proposedId, $margin]) {
            ReplacementRequest::firstOrCreate(
                [
                    'original_catalog_product_id' => $original->id,
                    'proposed_catalog_product_id' => $proposedId,
                    'status'                      => $status,
                ],
                ['margin_percent' => $margin],
            );
        }
    }
}
