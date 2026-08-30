<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-745 — endpoint de limpieza post-UAT. Cubre el caso feliz (borra todo
 * lo identificado por el prefijo UAT-), el caso RESTRICT (un producto UAT
 * referenciado desde una tabla con restrictOnDelete() se excluye de la
 * borrada en vez de tumbar toda la transacción) y el caso "nada que limpiar".
 *
 * Fixtures creados directo acá, no vía CatalogUatTestDataSeeder — ese seeder
 * se salta 'testing' a propósito (ver CatalogUatTestDataSeederTest), así que
 * en este entorno el catálogo arranca en 0 productos `UAT-%`.
 */
class CatalogUatTestDataCleanupControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function makeUatProduct(string $suffix): CatalogProduct
    {
        return CatalogProduct::create([
            'reference'      => "UAT-TEST-{$suffix}",
            'name'           => "[PRUEBA UAT] Producto {$suffix}",
            'brand'          => 'Data de Prueba UAT',
            'price_full'     => 50.00,
            'cost'           => 20.00,
            'stock_quantity' => 10,
            'is_active'      => true,
            'category'       => CatalogProduct::CATEGORIES[0],
        ]);
    }

    public function test_deletes_all_uat_products_and_families(): void
    {
        $a = $this->makeUatProduct('A');
        $b = $this->makeUatProduct('B');
        $family = CatalogProductFamily::create(['name' => '[PRUEBA UAT] Familia Test', 'is_active' => true]);
        $family->products()->attach([$a->id, $b->id]);

        $token    = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/catalog/uat-test-data/cleanup');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $response->assertOk();
        $response->assertJsonPath('deleted_products', 2);
        $response->assertJsonPath('deleted_families', 1);
        $response->assertJsonPath('skipped_products', []);
        $this->assertSame(0, CatalogProduct::where('reference', 'like', 'UAT-%')->count());
        $this->assertSame(0, CatalogProductFamily::where('name', 'like', '[PRUEBA UAT] %')->count());
    }

    public function test_reports_no_data_found_when_nothing_to_clean(): void
    {
        $token    = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/catalog/uat-test-data/cleanup');

        $response->assertOk();
        $response->assertJsonPath('deleted_products', 0);
        $response->assertJsonPath('message', 'No UAT test data found.');
    }

    public function test_skips_product_referenced_by_a_restrict_on_delete_fk(): void
    {
        $blocked   = $this->makeUatProduct('BLOCKED');
        $deletable = $this->makeUatProduct('OK');

        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');

        $requestId = DB::table("{$slug}_compras.bodega_zona_libre_requests")->insertGetId([
            'order_number' => 900001, 'status' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table("{$slug}_compras.bodega_zona_libre_request_lines")->insert([
            'bodega_zona_libre_request_id' => $requestId,
            'catalog_product_id'           => $blocked->id,
            'quantity'                     => 1,
            'unit_cost_snapshot'           => 10.00,
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);

        $token    = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/catalog/uat-test-data/cleanup');

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $response->assertOk();
        $response->assertJsonPath('deleted_products', 1);
        $response->assertJsonPath('skipped_products', [$blocked->id]);
        $this->assertNotNull(CatalogProduct::find($blocked->id));
        $this->assertNull(CatalogProduct::find($deletable->id));
    }
}
