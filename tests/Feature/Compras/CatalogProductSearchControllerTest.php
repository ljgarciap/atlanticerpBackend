<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-130 — a diferencia del buscador de Ventas & Diseño, este SÍ expone costo y referencia de
 * fábrica (Compras los necesita para armar una orden, ver docblock del controller).
 */
class CatalogProductSearchControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', ...$overrides]);
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/products')->assertStatus(401);
    }

    public function test_respuesta_incluye_costo_y_referencia_de_fabrica(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'NORDIC-40', 'factory_reference' => 'LMP-001',
            'name' => 'Lámpara colgante Nordic 40cm', 'description' => 'Lámpara colgante Nordic 40cm', 'price_full' => 150, 'cost' => 100,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.cost', 100);
        $response->assertJsonPath('data.0.factory_reference', 'LMP-001');
    }

    public function test_filtra_solo_productos_del_proveedor_elegido(): void
    {
        // REQ-130 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $lightCorp = $this->makeProvider(['name' => 'LightCorp']);
        $iluminex = $this->makeProvider(['name' => 'Iluminex SA']);
        CatalogProduct::create([
            'reference' => 'A1', 'name' => 'Producto LightCorp', 'description' => 'Producto LightCorp', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $lightCorp->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'A2', 'name' => 'Producto Iluminex', 'description' => 'Producto Iluminex', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $iluminex->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?provider_id={$lightCorp->id}")
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertContains('A1', $refs);
        $this->assertNotContains('A2', $refs);
    }

    /**
     * Lote 4 (SCRUM-246, comentario de Gerencia Test 2026-08-16) — método "Buscar en catálogo" de
     * Comparación de Referencias: `original_catalog_product_id` decora los resultados con
     * `similarity_percent` real, con la fórmula 50% texto + 30% precio + 20% margen.
     */
    public function test_con_original_catalog_product_id_expone_similarity_percent_de_un_candidato_muy_parecido(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $original = CatalogProduct::create([
            'reference' => 'ORIG-01', 'name' => 'Bombillo LED E27 blanco cálido', 'description' => 'Bombillo LED 9W',
            'category' => 'Iluminación LED Básica', 'price_full' => 80, 'cost' => 48, // margen 40%
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'CAND-01', 'name' => 'Bombillo LED E27 blanco cálido', 'description' => 'Bombillo LED 9W',
            'category' => 'Iluminación LED Básica', 'price_full' => 85, 'cost' => 52.7, // margen ~38%, texto idéntico
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?search=Bombillo&original_catalog_product_id={$original->id}")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data, 'el producto original nunca debe aparecer como su propio candidato.');
        $this->assertSame('CAND-01', $data[0]['reference']);
        $this->assertSame('Bombillo LED E27 blanco cálido', $data[0]['name']);
        $this->assertGreaterThan(85, $data[0]['similarity_percent'], 'texto idéntico + precio/margen muy cercanos debe dar similitud alta.');
    }

    public function test_sin_candidatos_en_la_misma_categoria_amplia_a_todas_con_penalizacion(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $original = CatalogProduct::create([
            'reference' => 'ORIG-02', 'name' => 'Bombillo LED E27 blanco cálido', 'description' => 'Bombillo LED 9W',
            'category' => 'Iluminación LED Básica', 'price_full' => 80, 'cost' => 48,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'CAND-02', 'name' => 'Bombillo LED E27 blanco cálido', 'description' => 'Bombillo LED 9W',
            'category' => 'Colgantes de Diseño', 'price_full' => 80, 'cost' => 48,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $withoutOriginal = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products?search=Bombillo')
            ->assertStatus(200);
        $withOriginal = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?search=Bombillo&original_catalog_product_id={$original->id}")
            ->assertStatus(200);

        // Sin original: comportamiento de REQ-130 intacto, similarity_percent siempre null.
        $this->assertNull($withoutOriginal->json('data.0.similarity_percent'));

        $data = $withOriginal->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('CAND-02', $data[0]['reference']);
        // Texto/precio/margen idénticos (100% cada componente) menos la penalización de 30 (default).
        $this->assertEquals(70.0, $data[0]['similarity_percent']);
    }

    public function test_candidato_bajo_el_umbral_minimo_se_descarta_silenciosamente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $original = CatalogProduct::create([
            'reference' => 'ORIG-03', 'name' => 'Bombillo LED E27 blanco cálido', 'description' => 'Bombillo LED 9W',
            'category' => 'Iluminación LED Básica', 'price_full' => 80, 'cost' => 48,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            // Ningún parecido de texto, precio muy lejano, margen muy distinto — similitud < 25%.
            'reference' => 'CAND-03', 'name' => 'Poste ornamental de jardín 2m', 'description' => 'Poste exterior forjado',
            'category' => 'Iluminación LED Básica', 'price_full' => 900, 'cost' => 850,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?search=Poste&original_catalog_product_id={$original->id}")
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }
}
