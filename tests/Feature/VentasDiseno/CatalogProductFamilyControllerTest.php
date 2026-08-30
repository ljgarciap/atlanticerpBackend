<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-037: tab "Familias" del buscador de Catálogo — listar familias con su
 * cantidad de productos, ver el detalle completo antes de confirmar.
 */
class CatalogProductFamilyControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'LAMP-001', 'name' => 'Lámpara colgante LED', 'description' => 'Lámpara colgante LED', 'brand' => 'Illuminations Home',
            'price_full' => 150.00, 'cost' => 80.00, 'stock_quantity' => 10, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/catalog-product-families')->assertStatus(401);
    }

    public function test_listado_incluye_cantidad_de_productos(): void
    {
        // No asume posición 0 — la BD local de infra/test.sh puede tener otras
        // familias sembradas de antes (ver memory/feedback_local_test_db_no_es_fresh.md).
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $p1 = $this->makeProduct(['reference' => 'A-001']);
        $p2 = $this->makeProduct(['reference' => 'A-002']);
        $family = CatalogProductFamily::create(['name' => 'Kit Baño Único', 'is_active' => true]);
        $family->products()->attach([$p1->id, $p2->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-product-families')
            ->assertStatus(200);

        $found = collect($response->json('data'))->firstWhere('name', 'Kit Baño Único');
        $this->assertNotNull($found);
        $this->assertSame(2, $found['products_count']);
    }

    public function test_listado_excluye_familias_inactivas(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        CatalogProductFamily::create(['name' => 'Activa', 'is_active' => true]);
        CatalogProductFamily::create(['name' => 'Inactiva', 'is_active' => false]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-product-families')
            ->assertStatus(200);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('Activa', $names);
        $this->assertNotContains('Inactiva', $names);
    }

    public function test_detalle_incluye_productos_sin_costo(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $product = $this->makeProduct(['reference' => 'B-001']);
        $family = CatalogProductFamily::create(['name' => 'Kit Sala', 'is_active' => true]);
        $family->products()->attach($product->id);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog-product-families/{$family->id}")
            ->assertStatus(200);

        $response->assertJsonPath('name', 'Kit Sala');
        $response->assertJsonPath('products.0.reference', 'B-001');
        $response->assertJsonPath('products.0.stock_quantity', 10);
        $this->assertArrayNotHasKey('cost', $response->json('products.0'));
    }

    public function test_detalle_excluye_producto_inactivo_de_la_familia(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $active   = $this->makeProduct(['reference' => 'C-001', 'is_active' => true]);
        $inactive = $this->makeProduct(['reference' => 'C-002', 'is_active' => false]);
        $family = CatalogProductFamily::create(['name' => 'Mixta', 'is_active' => true]);
        $family->products()->attach([$active->id, $inactive->id]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog-product-families/{$family->id}")
            ->assertStatus(200);

        $refs = collect($response->json('products'))->pluck('reference');
        $this->assertContains('C-001', $refs);
        $this->assertNotContains('C-002', $refs);
    }

    public function test_detalle_familia_inactiva_retorna_404(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $family = CatalogProductFamily::create(['name' => 'Inactiva', 'is_active' => false]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog-product-families/{$family->id}")
            ->assertStatus(404);
    }
}
