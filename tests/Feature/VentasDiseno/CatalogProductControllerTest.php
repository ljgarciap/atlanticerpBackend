<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\DemoModeTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-036: buscador de productos del Catálogo — ficha técnica, tolerancia a
 * errores de tipeo. Ver memory/project_backlog_ventas_diseno_req.md.
 */
class CatalogProductControllerTest extends FeatureTestCase
{
    use DemoModeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'LAMP-001', 'name' => 'Lámpara colgante LED', 'description' => 'Lámpara colgante LED', 'brand' => 'Atlantic Home',
            'price_full' => 150.00, 'cost' => 80.00, 'stock_quantity' => 10, 'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/catalog-products')->assertStatus(401);
    }

    public function test_listado_incluye_solo_productos_activos(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct(['reference' => 'ACT-001', 'is_active' => true]);
        $this->makeProduct(['reference' => 'INACT-001', 'is_active' => false]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertContains('ACT-001', $refs);
        $this->assertNotContains('INACT-001', $refs);
    }

    public function test_ficha_tecnica_nunca_incluye_el_costo(): void
    {
        // REQ-036 AC2: la ficha técnica muestra foto/marca/descripción/
        // disponible/precio — el costo es información de margen interna,
        // nunca debe viajar por HTTP hacia el buscador.
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $this->assertArrayNotHasKey('cost', $response->json('data.0'));
        $response->assertJsonPath('data.0.price_full', 150);
        $response->assertJsonPath('data.0.stock_quantity', 10);
        $response->assertJsonPath('data.0.brand', 'Atlantic Home');
    }

    /**
     * SCRUM-796 (secc. 5) — `stock_quantity` de este buscador ahora es el disponible NETO
     * (mismo cálculo que Catálogo/Bodega vía OrderCommitmentService), no el stock bruto.
     */
    public function test_stock_quantity_resta_lo_comprometido_en_bodega(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $product = $this->makeProduct(['reference' => 'NET-001', 'stock_quantity' => 20]);

        $order = Order::create([
            'order_number'  => 'PED-2026-'.random_int(1000, 999999),
            'order_type'    => Order::ORDER_TYPE_PEDIDO,
            'stage'         => Order::STAGE_EN_PICKING,
            'customer_name' => 'Cliente de prueba',
        ]);
        $order->items()->create([
            'catalog_product_id'   => $product->id,
            'qty_requested'        => 6,
            'reference_snapshot'   => $product->reference,
            'description_snapshot' => $product->name,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?search=NET-001')
            ->assertStatus(200);

        // 20 en stock - 6 comprometidos = 14 disponible, nunca el bruto (20).
        $response->assertJsonPath('data.0.stock_quantity', 14);
    }

    public function test_busqueda_exacta_por_referencia(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct(['reference' => 'SPOT-010', 'name' => 'Spot LED empotrable', 'description' => 'Spot LED empotrable']);
        $this->makeProduct(['reference' => 'CAND-099', 'name' => 'Candelabro de cristal', 'description' => 'Candelabro de cristal']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?search=SPOT-010')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', false);
        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertCount(1, $refs);
        $this->assertContains('SPOT-010', $refs);
    }

    /**
     * SCRUM-796 (secc. 6) — corrige el comportamiento anterior (SCRUM-734, aprobado):
     * un error de tipeo YA NO debe traer resultados por similitud heurística — sin
     * coincidencia exacta ni parcial real, la búsqueda no debe devolver nada.
     */
    public function test_busqueda_sin_coincidencia_exacta_ni_parcial_no_trae_resultados_por_similitud(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct(['reference' => 'LAMP-001', 'name' => 'Lámpara colgante industrial', 'description' => 'Lámpara colgante industrial']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        // Error de tipeo: "colgante" -> "colgnate" — no es substring de ningún campo.
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?search=colgnate')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', false);
        $this->assertCount(0, $response->json('data'));
    }

    /** SCRUM-796 (secc. 6) — coincidencia parcial real (substring) sigue funcionando. */
    public function test_busqueda_parcial_por_substring_de_nombre(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct(['reference' => 'LAMP-001', 'name' => 'Lámpara colgante industrial', 'description' => 'Lámpara colgante industrial']);
        $this->makeProduct(['reference' => 'CAND-099', 'name' => 'Candelabro de cristal', 'description' => 'Candelabro de cristal']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?search=colgante')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', false);
        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertContains('LAMP-001', $refs);
        $this->assertNotContains('CAND-099', $refs);
    }

    /**
     * SCRUM-796 (secc. 6) — la coincidencia EXACTA de referencia debe aparecer antes que
     * las parciales, aunque estas también matcheen el término buscado.
     */
    public function test_busqueda_prioriza_coincidencia_exacta_sobre_parcial(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct(['reference' => 'ABC-123X', 'name' => 'Variante parcial', 'description' => 'Variante parcial']);
        $this->makeProduct(['reference' => 'ABC-123', 'name' => 'Coincidencia exacta', 'description' => 'Coincidencia exacta']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?search=ABC-123')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertSame('ABC-123', $refs->first());
        $this->assertContains('ABC-123X', $refs);
    }

    /** SCRUM-796 (sección 5/6) — el tope de resultados (6) sigue aplicando a la coincidencia parcial. */
    public function test_busqueda_parcial_trae_hasta_6_resultados(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        for ($i = 1; $i <= 8; $i++) {
            $this->makeProduct(['reference' => "LAMP-00{$i}", 'name' => 'Lámpara colgante LED', 'description' => 'Lámpara colgante LED']);
        }

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?search=LAMP')
            ->assertStatus(200);

        $response->assertJsonPath('fuzzy', false);
        $this->assertCount(6, $response->json('data'));
    }

    public function test_show_retorna_ficha_tecnica_sin_costo(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog-products/{$product->id}")
            ->assertStatus(200);

        $this->assertArrayNotHasKey('cost', $response->json());
        $response->assertJsonPath('reference', 'LAMP-001');
    }

    public function test_show_producto_inactivo_retorna_404(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $product = $this->makeProduct(['is_active' => false]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog-products/{$product->id}")
            ->assertStatus(404);
    }

    // ── Modo Demo (docs/specs/demo-mode.md) ─────────────────────────────────

    public function test_gate_global_apagado_muestra_catalogo_completo_aunque_preferencia_personal_este_activa(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->setUserDemoModePreference('designer@atlantic.test', true);
        // Gate global queda en default (apagado) -- no se llama enableDemoModeGlobally().
        $this->makeProduct(['reference' => 'SIN-FOTO', 'photo_url' => null]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $this->assertContains('SIN-FOTO', collect($response->json('data'))->pluck('reference'));
    }

    public function test_gate_global_activo_pero_preferencia_personal_apagada_muestra_catalogo_completo(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference('designer@atlantic.test', false);
        $this->makeProduct(['reference' => 'SIN-FOTO', 'photo_url' => null]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $this->assertContains('SIN-FOTO', collect($response->json('data'))->pluck('reference'));
    }

    public function test_ambos_gates_activos_excluye_producto_sin_imagen(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference('designer@atlantic.test', true);
        $this->makeProduct(['reference' => 'SIN-FOTO', 'photo_url' => null, 'photo_key' => null]);
        $this->makeProduct(['reference' => 'CON-FOTO', 'photo_url' => 'https://cdn.test/foto.jpg']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertNotContains('SIN-FOTO', $refs);
        $this->assertContains('CON-FOTO', $refs);
    }

    public function test_ambos_gates_activos_excluye_producto_con_precio_cero(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference('designer@atlantic.test', true);
        $this->makeProduct(['reference' => 'SIN-PRECIO', 'photo_url' => 'https://cdn.test/foto.jpg', 'price_full' => 0]);
        $this->makeProduct(['reference' => 'CON-PRECIO', 'photo_url' => 'https://cdn.test/foto.jpg', 'price_full' => 150]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertNotContains('SIN-PRECIO', $refs);
        $this->assertContains('CON-PRECIO', $refs);
    }

    public function test_ambos_gates_activos_incluye_producto_con_photo_key_pero_photo_url_vacio(): void
    {
        // Precedencia de CatalogService::photoUrl(): photo_key gana si existe. Un producto con
        // photo_key pero SIN photo_url (foto subida por el mecanismo nuevo, SCRUM-830) debe
        // seguir contando como "tiene imagen" en Modo Demo.
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference('designer@atlantic.test', true);
        $this->makeProduct(['reference' => 'FOTO-NUEVA', 'photo_url' => null, 'photo_key' => 'ventas_diseno/foo.jpg']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products')
            ->assertStatus(200);

        $this->assertContains('FOTO-NUEVA', collect($response->json('data'))->pluck('reference'));
    }

    // SCRUM-1138 — el buscador de productos (vista previa antes de agregar a la cotización) resuelve la foto subida.
    public function test_1138_buscador_resuelve_la_foto_subida_y_conserva_la_legacy(): void
    {
        config(['filesystems.disks.s3-public.cdn_url' => 'https://cdn.test']);
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $this->makeProduct(['reference' => 'FOTO-SUB', 'photo_url' => null, 'photo_key' => 'catalog/5/photo/a.jpg', 'photo_thumbnail_key' => 'catalog/5/photo/a-thumb.jpg']);
        $this->makeProduct(['reference' => 'FOTO-LEG', 'photo_url' => 'https://legacy.test/l.jpg']);
        $this->makeProduct(['reference' => 'FOTO-SIN', 'photo_url' => null]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $rows = collect($this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog-products?per_page=all')
            ->assertStatus(200)->json('data'))->keyBy('reference');

        $this->assertSame('https://cdn.test/public/catalog/5/photo/a-thumb.jpg', $rows['FOTO-SUB']['photo_url']);
        $this->assertSame('https://legacy.test/l.jpg', $rows['FOTO-LEG']['photo_url']);
        $this->assertNull($rows['FOTO-SIN']['photo_url']);
    }
}

