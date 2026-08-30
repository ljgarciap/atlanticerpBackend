<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-745 — regresión del guard de entorno de CatalogUatTestDataSeeder.
 *
 * No se testea el contenido del seed acá (mismo criterio que CatalogProductSeeder/
 * CatalogProductFamilySeeder — sin tests dedicados, siempre gateados fuera de
 * 'testing'): `catalog_products` es una tabla compartida con Bodega/Compras — 30
 * productos UAT con rotación/estado/stock variados ensucian conteos, facetas y
 * dropdowns de tests que consultan el catálogo completo (confirmado 2026-08-11:
 * 16 tests rotos en BodegaInventoryControllerTest/InventoryControllerTest/
 * CatalogControllerTest/PurchaseOrderControllerTest al correr sin este guard —
 * ver memory/feedback_catalog_product_shared_table_pollution.md). Contenido
 * validado corriendo el seeder de verdad contra el stack Docker local
 * (`php artisan db:seed --class=CatalogUatTestDataSeeder`), no en PHPUnit.
 */
class CatalogUatTestDataSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_skips_seeding_entirely_in_testing_environment(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(0, CatalogProduct::where('reference', 'like', 'UAT-%')->count());
        $this->assertSame(0, CatalogProductFamily::where('name', 'like', '[PRUEBA UAT] %')->count());
    }
}
