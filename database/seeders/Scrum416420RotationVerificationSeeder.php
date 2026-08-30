<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Siembra ad-hoc para verificar en vivo SCRUM-416 (KPI "En atención" de Bodega) y SCRUM-420 (chip
 * "Atención") tras el fix de Pre-QA 2026-07-28
 * (docs/pre-qa/scrum416-420-en-atencion-rotacion-20260728.md): Bodega pasó de reusar el BLOCKLIST
 * de `InventoryKpiService::isInAttention()` (Compras/REQ-169) a su propio ALLOWLIST
 * (`BodegaInventoryKpiService::isInAttentionBodega()`).
 *
 * Verificado por SSH (dev.atlanticerp.ai, 2026-07-28) que el catálogo real NO tenía ningún producto con
 * `rotation = 'baja'` — la verificación previa de Pre-QA (2026-07-23, CAND-SAL-005/PISO-LAMP-030)
 * fue en memoria vía tinker, nunca persistida. Sí existían productos `rotation IS NULL` con stock
 * bajo/sin stock (ej. `PREQA-235-A-*`) y uno `rotation = 'media'` en 0 unidades (`QA-BOD-406-001`),
 * suficientes para cubrir esos dos casos — este seeder solo agrega los que faltaban (`alta` y
 * `baja`), y un `null` propio con nombre autoexplicativo para no depender de fixtures ajenos de
 * otro batch.
 *
 * NO se registra en `DatabaseSeeder::run()` — se invoca a mano vía tinker en cada entorno:
 *
 *   (new \Database\Seeders\Scrum416420RotationVerificationSeeder)->run();
 *
 * Idempotente por `reference` (updateOrCreate) — correr de nuevo no duplica filas.
 */
final class Scrum416420RotationVerificationSeeder extends Seeder
{
    private const REF_PREFIX = 'SCRUM416-ROT-';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "illuminations" no encontrado — seeder abortado.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $rows = [
                // Debe contar en "En atención" (Bodega) — rotación alta + sin stock.
                self::REF_PREFIX.'ALTA' => [
                    'description' => '[QA-ROT] Verificación En Atención — rotación alta, sin stock',
                    'rotation'    => CatalogProduct::ROTATION_ALTA,
                ],
                // Debe contar en "En atención" (Bodega) — rotación media + sin stock.
                self::REF_PREFIX.'MEDIA' => [
                    'description' => '[QA-ROT] Verificación En Atención — rotación media, sin stock',
                    'rotation'    => CatalogProduct::ROTATION_MEDIA,
                ],
                // NUNCA debe contar en "En atención" de Bodega (allowlist), pero SÍ debe seguir
                // contando en "En atención" de Compras (blocklist, REQ-169) — caso central del fix.
                self::REF_PREFIX.'BAJA' => [
                    'description' => '[QA-ROT] Verificación En Atención — rotación baja, sin stock',
                    'rotation'    => CatalogProduct::ROTATION_BAJA,
                ],
                // NUNCA debe contar en "En atención" de Bodega (allowlist exige alta/media), pero SÍ
                // debe seguir contando en "En atención" de Compras (blocklist cuenta NULL).
                self::REF_PREFIX.'NULL' => [
                    'description' => '[QA-ROT] Verificación En Atención — sin clasificar, sin stock',
                    'rotation'    => null,
                ],
            ];

            foreach ($rows as $reference => $attrs) {
                CatalogProduct::updateOrCreate(
                    ['reference' => $reference],
                    [
                        // SCRUM-237/240 — `name` NOT NULL (ver migración 2026_08_15_100000_...).
                        'name'           => $attrs['description'],
                        'brand'          => 'QA Fixture',
                        'price_full'     => 50.0,
                        'cost'           => 25.0,
                        'stock_quantity' => 0,
                        'reorder_point'  => 5,
                        'rotation'       => $attrs['rotation'],
                        'is_active'      => true,
                    ],
                );
            }

            $this->command?->info('Scrum416420RotationVerificationSeeder: 4 productos sembrados/actualizados ('.self::REF_PREFIX.'ALTA/MEDIA/BAJA/NULL).');
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
