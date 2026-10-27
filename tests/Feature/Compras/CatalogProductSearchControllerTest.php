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
        return Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD', ...$overrides]);
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
            'name' => 'Vástago colgante Nordic 40cm', 'description' => 'Vástago colgante Nordic 40cm', 'price_full' => 150, 'cost' => 100,
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
        $proveedorA = $this->makeProvider(['name' => 'Proveedor Demo A']);
        $proveedorB = $this->makeProvider(['name' => 'Proveedor Demo B SA']);
        CatalogProduct::create([
            'reference' => 'A1', 'name' => 'Producto Proveedor Demo A', 'description' => 'Producto Proveedor Demo A', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $proveedorA->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'A2', 'name' => 'Producto Proveedor Demo B', 'description' => 'Producto Proveedor Demo B', 'price_full' => 10, 'cost' => 5,
            'provider_id' => $proveedorB->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?provider_id={$proveedorA->id}")
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
            'reference' => 'ORIG-01', 'name' => 'Cilindro XL E27 blanco cálido', 'description' => 'Cilindro XL 9W',
            'category' => 'Catalogación XL Básica', 'price_full' => 80, 'cost' => 48, // margen 40%
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'CAND-01', 'name' => 'Cilindro XL E27 blanco cálido', 'description' => 'Cilindro XL 9W',
            'category' => 'Catalogación XL Básica', 'price_full' => 85, 'cost' => 52.7, // margen ~38%, texto idéntico
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?search=Cilindro&original_catalog_product_id={$original->id}")
            ->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data, 'el producto original nunca debe aparecer como su propio candidato.');
        $this->assertSame('CAND-01', $data[0]['reference']);
        $this->assertSame('Cilindro XL E27 blanco cálido', $data[0]['name']);
        $this->assertGreaterThan(85, $data[0]['similarity_percent'], 'texto idéntico + precio/margen muy cercanos debe dar similitud alta.');
    }

    public function test_sin_candidatos_en_la_misma_categoria_amplia_a_todas_con_penalizacion(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $original = CatalogProduct::create([
            'reference' => 'ORIG-02', 'name' => 'Cilindro XL E27 blanco cálido', 'description' => 'Cilindro XL 9W',
            'category' => 'Catalogación XL Básica', 'price_full' => 80, 'cost' => 48,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            'reference' => 'CAND-02', 'name' => 'Cilindro XL E27 blanco cálido', 'description' => 'Cilindro XL 9W',
            'category' => 'Colgantes de Diseño', 'price_full' => 80, 'cost' => 48,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $withoutOriginal = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products?search=Cilindro')
            ->assertStatus(200);
        $withOriginal = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?search=Cilindro&original_catalog_product_id={$original->id}")
            ->assertStatus(200);

        // Sin original: comportamiento de REQ-130 intacto, similarity_percent siempre null.
        $this->assertNull($withoutOriginal->json('data.0.similarity_percent'));

        $data = $withOriginal->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('CAND-02', $data[0]['reference']);
        // Texto/precio/margen idénticos (100% cada componente) menos la penalización de 30 (default).
        $this->assertEquals(70.0, $data[0]['similarity_percent']);
    }

    // ── SCRUM-818 — vendedor/diseñador puede buscar (Comparación de Referencias), pero sin costo ──

    /** Antes 403 total (no tenía ninguno de los 3 permisos que ya aceptaba este endpoint). */
    public function test_vendedor_puede_buscar_via_ventas_diseno_edit(): void
    {
        $this->grantCompras('designer@atlantic.test', ['ventas_diseno.edit']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'VD-01', 'name' => 'Cilindro para vendedor', 'description' => 'Cilindro para vendedor',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products')
            ->assertStatus(200);
    }

    /**
     * Este endpoint SÍ expone costo a propósito para Compras (ver docblock del controller) — pero
     * el vendedor que entra solo con ventas_diseno.edit (sin ningún permiso real de Compras) nunca
     * debe ver el costo interno del producto, mismo criterio que el buscador de Ventas & Diseño.
     */
    public function test_vendedor_via_ventas_diseno_edit_no_ve_costo(): void
    {
        $this->grantCompras('designer@atlantic.test', ['ventas_diseno.edit']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'VD-02', 'name' => 'Cilindro sin costo para vendedor', 'description' => 'Cilindro sin costo para vendedor',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products')
            ->assertStatus(200);

        $this->assertNull($response->json('data.0.cost'));
        // SCRUM-818 (rebote 2026-09-03, Hallazgo 5) — el % de margen SÍ viaja para Vendedor,
        // aunque el costo/precio de compra crudos sigan sin corresponderle (decisión de Luis).
        $this->assertEquals(40, $response->json('data.0.margin_percent')); // (50-30)/50 × 100
    }

    /** Con compras.read real (Compras/Gerencia), el costo sigue viajando igual que siempre. */
    public function test_usuario_de_compras_sigue_viendo_costo(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'ventas_diseno.edit']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'VD-03', 'name' => 'Cilindro con costo', 'description' => 'Cilindro con costo',
            'price_full' => 50, 'cost' => 30, 'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/products')
            ->assertStatus(200);

        $this->assertEquals(30, $response->json('data.0.cost'));
        $this->assertEquals(40, $response->json('data.0.margin_percent'));
    }

    public function test_candidato_bajo_el_umbral_minimo_se_descarta_silenciosamente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $original = CatalogProduct::create([
            'reference' => 'ORIG-03', 'name' => 'Cilindro XL E27 blanco cálido', 'description' => 'Cilindro XL 9W',
            'category' => 'Catalogación XL Básica', 'price_full' => 80, 'cost' => 48,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        CatalogProduct::create([
            // Ningún parecido de texto, precio muy lejano, margen muy distinto — similitud < 25%.
            'reference' => 'CAND-03', 'name' => 'Poste ornamental de jardín 2m', 'description' => 'Poste exterior forjado',
            'category' => 'Catalogación XL Básica', 'price_full' => 900, 'cost' => 850,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?search=Poste&original_catalog_product_id={$original->id}")
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    /** SCRUM-992 — búsqueda en SQL, respaldo tolerante a typos y tope de resultados. */
    public function test_busqueda_sin_acentos_y_typo_con_respaldo(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        CatalogProduct::create([
            'reference' => 'NEW-1', 'name' => 'Vástago colgante Nordic', 'description' => 'x', 'price_full' => 10,
            'provider_id' => $provider->id, 'is_active' => true,
        ]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $ok = $this->withHeaders($this->authHeaders($token))->getJson('/api/compras/products?search=VASTAGO nordic')->assertStatus(200);
        $this->assertSame(['NEW-1'], collect($ok->json('data'))->pluck('reference')->all());
        $this->assertFalse($ok->json('fuzzy'));

        $typo = $this->withHeaders($this->authHeaders($token))->getJson('/api/compras/products?search=vastgo colgnte')->assertStatus(200);
        $this->assertSame(['NEW-1'], collect($typo->json('data'))->pluck('reference')->all());
        $this->assertTrue($typo->json('fuzzy'));
    }

    public function test_resultados_acotados_y_exacta_primero(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        foreach (range(1, 105) as $i) {
            CatalogProduct::create([
                'reference' => sprintf('BULK-%03d', $i), 'name' => 'Bulk '.$i, 'description' => 'x', 'price_full' => 10,
                'provider_id' => $provider->id, 'is_active' => true,
            ]);
        }
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $all = $this->withHeaders($this->authHeaders($token))->getJson('/api/compras/products')->assertStatus(200);
        $this->assertCount(100, $all->json('data'));

        $exact = $this->withHeaders($this->authHeaders($token))->getJson('/api/compras/products?search=BULK-042')->assertStatus(200);
        $this->assertSame('BULK-042', $exact->json('data.0.reference'));
    }

    // SCRUM-1163 — paginación real en servidor: el total es el del catálogo del proveedor, no el tope de 100.
    public function test_paginacion_real_devuelve_total_y_recorre_todo_el_catalogo_del_proveedor(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        foreach (range(1, 130) as $i) {
            CatalogProduct::create([
                'reference' => sprintf('PAG-%03d', $i), 'name' => 'Pag '.$i, 'description' => 'x', 'price_full' => 10,
                'provider_id' => $provider->id, 'is_active' => true,
            ]);
        }
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $get = fn (string $qs) => $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/products?provider_id={$provider->id}&{$qs}")->assertStatus(200);

        $p1 = $get('page=1&per_page=50');
        $this->assertCount(50, $p1->json('data'));
        $this->assertSame(130, $p1->json('meta.total'));
        $this->assertSame(3, $p1->json('meta.last_page'));

        $p3 = $get('page=3&per_page=50');
        $this->assertCount(30, $p3->json('data'));   // los productos 101-130 que antes eran inalcanzables

        $todos = collect([1, 2, 3])->flatMap(fn ($n) => $get("page={$n}&per_page=50")->json('data'))->pluck('id');
        $this->assertCount(130, $todos->unique(), 'Ninguna página repite ni omite productos.');
    }

    public function test_paginacion_real_respeta_busqueda_y_excluye_los_ya_agregados(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $ids = [];
        foreach (range(1, 12) as $i) {
            $ids[] = CatalogProduct::create([
                'reference' => sprintf('EXC-%02d', $i), 'name' => 'Exc '.$i, 'description' => 'x', 'price_full' => 10,
                'provider_id' => $provider->id, 'is_active' => true,
            ])->id;
        }
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $r = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?provider_id={$provider->id}&page=1&per_page=10&exclude_ids[]={$ids[0]}&exclude_ids[]={$ids[1]}")
            ->assertStatus(200);

        $this->assertSame(10, $r->json('meta.total'));   // 12 menos 2 excluidos
        $this->assertNotContains($ids[0], collect($r->json('data'))->pluck('id')->all());
        $this->assertNotContains($ids[1], collect($r->json('data'))->pluck('id')->all());

        $busca = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?provider_id={$provider->id}&page=1&per_page=10&search=EXC-05")->assertStatus(200);
        $this->assertSame('EXC-05', $busca->json('data.0.reference'));
    }

    public function test_paginacion_real_con_proveedor_sin_productos_o_busqueda_sin_resultados_devuelve_total_cero(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $vacio = $this->makeProvider();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $r = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/products?provider_id={$vacio->id}&page=1&per_page=20")->assertStatus(200);
        $this->assertSame(0, $r->json('meta.total'));
        $this->assertSame([], $r->json('data'));
    }

    public function test_per_page_se_acota_y_una_pagina_fuera_de_rango_no_rompe(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        CatalogProduct::create(['reference' => 'ONE-1', 'name' => 'One', 'description' => 'x', 'price_full' => 10, 'provider_id' => $provider->id, 'is_active' => true]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $get = fn (string $qs) => $this->withHeaders($this->authHeaders($token))->getJson("/api/compras/products?provider_id={$provider->id}&{$qs}");

        $this->assertSame(100, $get('page=1&per_page=100000')->assertStatus(200)->json('meta.per_page'));
        $this->assertSame(1, $get('page=1&per_page=0')->assertStatus(200)->json('meta.per_page'));
        $fuera = $get('page=99&per_page=20')->assertStatus(200);
        $this->assertSame([], $fuera->json('data'));
        $this->assertSame(1, $fuera->json('meta.total'));
        $get('page=abc&per_page=20')->assertStatus(200);   // no numérico: cae al modo sin paginar, sin error
    }
}
