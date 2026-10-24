<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\Scrum831InventoryTestDataSeeder;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-831 — regresión del guard de entorno del seeder ad-hoc. No se testea la distribución de
 * cantidades acá (mismo criterio que CatalogUatTestDataSeederTest): el seeder nunca corre en
 * 'testing', y ninguna de las 15 referencias reales de ICG existe en los fixtures sintéticos de
 * la suite. Contenido/reglas de cantidad validados corriendo el seeder de verdad contra el stack
 * Docker local o test.atlanticerp.ai, no en PHPUnit.
 */
class Scrum831InventoryTestDataSeederTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_skips_entirely_in_testing_environment_without_touching_matching_products(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $product = CatalogProduct::create([
            'reference'      => '0535-01',
            'name'           => 'Producto de prueba SCRUM-831',
            'price_full'     => 10.00,
            'stock_quantity' => 999,
            'is_active'      => true,
        ]);

        (new Scrum831InventoryTestDataSeeder())->run();

        $this->assertSame(999, $product->fresh()->stock_quantity);
    }
}
