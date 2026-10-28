<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-695→701 (REQ-615→621, Batch E del Epic CRM SCRUM-332) — pantalla "Catálogo": vista
 * comercial sin costos del inventario real, filtros combinables, ficha técnica (archivo real, no
 * `technical_spec`), PDF de ejemplo. Ver docblock de `CatalogService` para el detalle de diseño.
 */
class CatalogControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference'      => 'IL-DEC-1410A',
            'name'    => 'Producto colgante XL, 18W, 3000K', 'description'    => 'Producto colgante XL, 18W, 3000K',
            'brand'          => '770 Lights',
            'price_full'     => 522.00,
            'cost'           => 300.00,
            'import_cost'    => 20.00,
            'freight_cost'   => 10.00,
            'handling_cost'  => 5.00,
            'other_cost'     => 2.00,
            'stock_quantity' => 14,
            'category'       => 'general',
            'is_active'      => true,
            ...$overrides,
        ]);
    }

    // ── Criterio duro REQ-615 Escenario 1 — nunca costo/margen ──────────────────

    public function test_listado_nunca_expone_costo_ni_margen(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $row = $response->json('data.0');
        foreach (['cost', 'import_cost', 'freight_cost', 'handling_cost', 'other_cost', 'cost_total', 'margin_percent', 'por_servir', 'por_ingresar', 'estado', 'rotation', 'provider_id', 'provider_name'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "El campo [{$forbidden}] nunca debe exponerse en Catálogo (REQ-615 RN1).");
        }
    }

    public function test_detalle_nunca_expone_costo_ni_margen(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog/{$product->id}")
            ->assertStatus(200);

        foreach (['cost', 'import_cost', 'cost_total', 'margin_percent'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $response->json());
        }
    }

    // ── REQ-615 RN2 — stock simplificado ────────────────────────────────────────

    public function test_stock_simplificado_resta_lo_comprometido(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct(['stock_quantity' => 14]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        // Sin compromisos de Bodega en este fixture: disponible == stock_quantity.
        $response->assertJsonPath('data.0.disponible', 14);
        $this->assertArrayNotHasKey('por_servir', $response->json('data.0'));
    }

    public function test_stock_cero_no_es_negativo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['stock_quantity' => 0]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.disponible', 0);
    }

    // ── REQ-615 RN3 / REQ-617 RN2/RN3 — categoría vs familia, filtros independientes ─────

    public function test_categoria_y_familia_son_filtros_independientes(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $family = CatalogProductFamily::create(['name' => 'Kit Baño', 'is_active' => true]);

        $withFamily = $this->makeProduct(['reference' => 'A-1', 'category' => 'general']);
        $withFamily->families()->attach($family->id);
        $this->makeProduct(['reference' => 'A-2', 'category' => 'otra']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Filtrar solo por categoría no exige familia.
        $byCategory = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog?category=general')
            ->assertStatus(200);
        $this->assertEquals(['A-1'], collect($byCategory->json('data'))->pluck('reference')->all());

        // Filtrar solo por familia no exige categoría.
        $byFamily = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog?family_id={$family->id}")
            ->assertStatus(200);
        $this->assertEquals(['A-1'], collect($byFamily->json('data'))->pluck('reference')->all());
    }

    public function test_dropdown_de_categorias_solo_incluye_las_que_tienen_producto_activo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['reference' => 'A-1', 'category' => 'general']);
        $this->makeProduct(['reference' => 'A-2', 'category' => 'otra', 'is_active' => false]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $values = collect($response->json('categories'))->pluck('value');
        $this->assertContains('general', $values);
        $this->assertNotContains('otra', $values);
    }

    // ── REQ-617 RN1 Escenario 1 — búsqueda universal, matchea nombre de familia ──

    public function test_busqueda_matchea_nombre_de_familia_aunque_no_este_seleccionada(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $family = CatalogProductFamily::create(['name' => 'Kit Exterior Premium', 'is_active' => true]);
        $product = $this->makeProduct(['reference' => 'EXT-01', 'name' => 'Reflector XL', 'description' => 'Reflector XL']);
        $product->families()->attach($family->id);
        $this->makeProduct(['reference' => 'OTHER-01', 'name' => 'Spot interior', 'description' => 'Spot interior']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog?search=' . urlencode('kit exterior'))
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertContains('EXT-01', $refs);
        $this->assertNotContains('OTHER-01', $refs);
    }

    /** Fase 9a — la búsqueda matchea la ETIQUETA de la categoría (vocabulario de la empresa), no solo su clave. */
    public function test_busqueda_matchea_la_etiqueta_de_la_categoria(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['reference' => 'CAT-1', 'name' => 'Pieza uno', 'description' => 'Pieza uno', 'category' => 'herramientas']);
        $this->makeProduct(['reference' => 'CAT-2', 'name' => 'Pieza dos', 'description' => 'Pieza dos', 'category' => 'general']);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        \App\Shared\Catalogs\CatalogEntry::query()->create([
            'domain' => 'product_category', 'key' => 'herramientas', 'label' => 'Utensilios', 'sort_order' => 5, 'is_active' => true, 'is_system' => false,
        ]);
        app(\App\Shared\Catalogs\CatalogCache::class)->forget();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $refs = collect($this->withHeaders($this->authHeaders($token))->getJson('/api/ventas-diseno/catalog?search=utensilios')
            ->assertStatus(200)->json('data'))->pluck('reference');

        $this->assertContains('CAT-1', $refs);
        $this->assertNotContains('CAT-2', $refs);
    }

    // ── REQ-617 RN4 — filtro de stock ────────────────────────────────────────────

    public function test_filtro_stock_con_y_sin(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['reference' => 'CON-STOCK', 'stock_quantity' => 5]);
        $this->makeProduct(['reference' => 'SIN-STOCK', 'stock_quantity' => 0]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $con = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog?stock=con')->assertStatus(200);
        $this->assertEquals(['CON-STOCK'], collect($con->json('data'))->pluck('reference')->all());

        $sin = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog?stock=sin')->assertStatus(200);
        $this->assertEquals(['SIN-STOCK'], collect($sin->json('data'))->pluck('reference')->all());
    }

    // ── REQ-615 RN4 / REQ-618 — ficha técnica (archivo real) ─────────────────────

    public function test_badge_sin_ficha_por_default(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.has_technical_sheet', false);
    }

    public function test_subir_ficha_tecnica_pdf_exitoso(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $file   = UploadedFile::fake()->create('ficha.pdf', 500, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $file])
            ->assertStatus(200);

        $response->assertJsonPath('has_technical_sheet', true);
        $response->assertJsonPath('technical_sheet_filename', 'ficha.pdf');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $product->refresh();
        $this->assertNotNull($product->technical_sheet_key);
        Storage::disk('s3-private')->assertExists($product->technical_sheet_key);
    }

    public function test_subir_ficha_tecnica_formato_invalido_retorna_422(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $file   = UploadedFile::fake()->create('ficha.dwg', 100);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $file])
            ->assertStatus(422);
    }

    /**
     * REQ-618 RN1 — "sin límite de tamaño artificial de la app": un archivo grande (10MB, muy por
     * encima de cualquier límite típico de formulario) debe aceptarse porque no hay regla `max:`
     * en `StoreCatalogTechnicalSheetRequest` — el único techo real es el de PHP/Nginx del entorno.
     */
    public function test_subir_ficha_tecnica_sin_limite_de_tamano_artificial(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $file   = UploadedFile::fake()->create('ficha-grande.pdf', 10 * 1024, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $file])
            ->assertStatus(200);
    }

    public function test_reemplazar_ficha_tecnica_borra_la_anterior_de_s3(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $first])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $product->refresh();
        $oldKey = $product->technical_sheet_key;
        $this->assertNotNull($oldKey);

        $second = UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $second])
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $product->refresh();
        $this->assertNotEquals($oldKey, $product->technical_sheet_key);
        Storage::disk('s3-private')->assertMissing($oldKey);
        Storage::disk('s3-private')->assertExists($product->technical_sheet_key);
    }

    public function test_ver_documento_sin_ficha_retorna_404(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet")
            ->assertStatus(404);
    }

    public function test_ver_documento_con_ficha_retorna_url_presignada(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $file = UploadedFile::fake()->create('ficha.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $file])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet")
            ->assertStatus(200);

        $this->assertIsString($response->json('url'));
        $this->assertNotEmpty($response->json('url'));
    }

    // ── SCRUM-702 (REQ-622) — PDF dinámico real ──────────────────────────────────

    public function test_send_pdf_modo_completo_incluye_todos_los_productos(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['reference' => 'A-1']);
        $this->makeProduct(['reference' => 'A-2']);
        // Inactivo — nunca debe aparecer, ni siquiera en modo "completo".
        $this->makeProduct(['reference' => 'A-3', 'is_active' => false]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])
            ->assertStatus(200);

        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_send_pdf_modo_completo_ignora_product_ids_enviado_por_error(): void
    {
        // RN4 — "completo" siempre trae TODO el universo activo, ignorando cualquier
        // selección recibida (no debe fallar ni acotarse si el frontend manda product_ids igual).
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct(['reference' => 'A-1']);
        $this->makeProduct(['reference' => 'A-2']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', [
                'mode'        => 'completo',
                'product_ids' => [$product->id],
            ])
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_send_pdf_modo_seleccionados_devuelve_pdf(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct(['reference' => 'A-1']);
        $this->makeProduct(['reference' => 'A-2']);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', [
                'mode'        => 'seleccionados',
                'product_ids' => [$product->id],
            ])
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_send_pdf_seleccionados_sin_product_ids_retorna_422(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'seleccionados'])
            ->assertStatus(422);
    }

    public function test_send_pdf_seleccionados_con_product_ids_vacio_retorna_422(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'seleccionados', 'product_ids' => []])
            ->assertStatus(422);
    }

    public function test_send_pdf_modo_invalido_retorna_422(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'otro'])
            ->assertStatus(422);
    }

    public function test_send_pdf_product_id_inexistente_retorna_422(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/ventas-diseno/catalog/send-pdf', [
                'mode'        => 'seleccionados',
                'product_ids' => [999999],
            ])
            ->assertStatus(422);
    }

    public function test_send_pdf_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/ventas-diseno/catalog/send-pdf', ['mode' => 'completo'])
            ->assertStatus(401);
    }

    /**
     * RN1 (criterio duro, no negociable) — el array/DTO que llega a la vista Blade del PDF nunca
     * debe tener stock/disponibilidad, sin importar qué exponga `CatalogService::format()` para la
     * pantalla web. Se verifica directo contra `CatalogPdfService`, no contra el HTTP binario (un
     * PDF ya renderizado no es inspeccionable como array) — mismo espíritu que
     * `test_listado_nunca_expone_costo_ni_margen` de arriba, aplicado a la capa que arma los datos
     * del PDF en vez de al JSON del listado.
     */
    public function test_pdf_service_nunca_pasa_datos_de_stock_a_la_vista(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 25]);

        $reflection = new \ReflectionClass(\App\Modules\VentasDiseno\Services\CatalogPdfService::class);
        $format     = $reflection->getMethod('formatForPdf');
        $format->setAccessible(true);

        $service = app(\App\Modules\VentasDiseno\Services\CatalogPdfService::class);
        $row     = $format->invoke($service, $product->fresh());

        foreach (['stock_quantity', 'disponible', 'en_camino', 'por_servir', 'por_ingresar', 'cost', 'cost_total', 'margin_percent'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "El campo [{$forbidden}] nunca debe llegar a la vista del PDF de Catálogo (RN1).");
        }
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_listado_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/catalog')->assertStatus(401);
    }

    public function test_upload_solo_requiere_ventas_diseno_read_no_edit(): void
    {
        // REQ-618 PERMISOS: "sin restricción de rol específica — visible para cualquier usuario
        // con acceso a Catálogo". Este usuario tiene SOLO `.read`, sin `.edit`/`.write`, y el
        // upload debe funcionar igual — es una decisión ya tomada en la spec, no un descuido.
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $product = $this->makeProduct();

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $file   = UploadedFile::fake()->create('ficha.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/catalog/{$product->id}/technical-sheet", ['file' => $file])
            ->assertStatus(200);
    }

    // ── docs/specs/demo-mode.md — hallazgo 2026-09-04: esta ES la pantalla "Catálogo" real,
    // CatalogController::index() ≠ CatalogProductController::index() (buscador de Cotización) ──

    public function test_ambos_gates_apagados_muestra_catalogo_completo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['name' => 'Completo', 'photo_url' => 'https://cdn/foto.jpg']);
        $this->makeProduct(['reference' => 'IL-INCOMPLETO', 'name' => '', 'photo_url' => null, 'price_full' => 0]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_gate_global_on_personal_off_muestra_catalogo_completo(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $this->makeProduct(['name' => 'Completo', 'photo_url' => 'https://cdn/foto.jpg']);
        $this->makeProduct(['reference' => 'IL-INCOMPLETO', 'name' => '', 'photo_url' => null, 'price_full' => 0]);

        $admin = $this->loginAs();
        $this->withHeaders($this->authHeaders($admin))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_ambos_gates_activos_filtra_por_nombre_imagen_y_precio(): void
    {
        $this->grantVentasDisenoRead('vendedor@atlantic.test');
        $completo = $this->makeProduct(['name' => 'Completo', 'photo_url' => 'https://cdn/foto.jpg']);
        $this->makeProduct(['reference' => 'IL-SIN-NOMBRE',  'name' => '', 'photo_url' => 'https://cdn/x.jpg']);
        $this->makeProduct(['reference' => 'IL-SIN-IMAGEN',  'name' => 'Sin imagen', 'photo_url' => null]);
        $this->makeProduct(['reference' => 'IL-SIN-PRECIO',  'name' => 'Sin precio', 'photo_url' => 'https://cdn/y.jpg', 'price_full' => 0]);
        $this->makeProduct(['reference' => 'IL-PHOTO-KEY',   'name' => 'Con photo_key', 'photo_url' => null, 'photo_key' => 'ventas_diseno/foo.jpg']);

        $admin = $this->loginAs();
        $this->withHeaders($this->authHeaders($admin))
            ->patchJson('/api/settings/system', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/settings/preferences', ['demo_mode_enabled' => true])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/catalog')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference')->all();
        $this->assertContains($completo->reference, $refs, 'Producto completo debe verse en Modo Demo.');
        $this->assertContains('IL-PHOTO-KEY', $refs, 'photo_key sin photo_url igual cuenta como "tiene imagen".');
        $this->assertNotContains('IL-SIN-NOMBRE', $refs);
        $this->assertNotContains('IL-SIN-IMAGEN', $refs);
        $this->assertNotContains('IL-SIN-PRECIO', $refs);
    }
}
