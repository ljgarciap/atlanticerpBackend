<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\ReplacementRequest;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-248/249 (REQ-185/186) — Sustitutos, parte sin IA. */
class ReplacementRequestControllerTest extends FeatureTestCase
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

    private function makeProduct(array $overrides = []): CatalogProduct
    {
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD']);

        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo decorativo E27 Ámbar', 'description' => 'Bombillo decorativo E27 Ámbar',
            'price_full' => 20, 'cost' => 12, 'provider_id' => $provider->id, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /**
     * REQ-133 RN dura, revalidada server-side en StoreReplacementRequestRequest (Senior Review
     * 20260718) — "Generar solicitud" solo acepta un sales_project_id con un Pedido Aprobado real,
     * no cualquier SalesProject suelto.
     */
    private function makeApprovedSalesProject(string $name = 'Proyecto Test'): SalesProject
    {
        $owner = User::where('email', 'designer@atlantic.test')->firstOrFail();
        $salesProject = SalesProject::create(['name' => $name]);
        // SCRUM-723: confirmed_at (no folio) es lo que decide document_status == "approved".
        Quote::create([
            'sales_project_id' => $salesProject->id, 'owner_id' => $owner->id,
            'folio' => 'COT-'.random_int(1000, 9999), 'confirmed_at' => now(),
        ]);
        PipelineCard::create(['sales_project_id' => $salesProject->id, 'stage' => 'approved', 'owner_id' => $owner->id]);

        return $salesProject;
    }

    public function test_listado_filtra_por_estado(): void
    {
        // REQ-185 Escenario 1 — solo Rechazadas muestran "Generar orden" (verificado del lado
        // del frontend; acá se verifica que el filtro por status funciona).
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $product = $this->makeProduct();
        ReplacementRequest::create(['original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_PENDIENTE]);
        ReplacementRequest::create(['original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests?status=rechazada')
            ->assertStatus(200);

        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertEquals([ReplacementRequest::STATUS_RECHAZADA], $statuses->all());
    }

    public function test_generar_orden_precarga_el_producto_original_no_el_sustituto(): void
    {
        // REQ-186 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.write', 'compras.read']);
        $original = $this->makeProduct(['description' => 'Bombillo decorativo E27 Ámbar', 'cost' => 12]);
        $substitute = $this->makeProduct(['description' => 'Bombillo genérico E27', 'cost' => 8]);
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $original->id,
            'proposed_catalog_product_id' => $substitute->id,
            'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(200);

        $orderId = $response->json('order_id');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $order = PurchaseOrder::with('lines')->find($orderId);
        $this->assertNotNull($order);
        $this->assertSame(PurchaseOrder::STATUS_POR_APROBAR, $order->status);
        $this->assertSame($original->id, $order->lines->first()->catalog_product_id);
        $this->assertNotEquals($substitute->id, $order->lines->first()->catalog_product_id);
    }

    public function test_no_se_puede_generar_orden_de_solicitud_no_rechazada(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_PENDIENTE,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(422);
    }

    /**
     * Hallazgo de Senior Review (2026-07-17): catalog_products.provider_id es nullable (ver
     * migración add_provider_fields_to_ventas_diseno_catalog_products_table) — un producto del
     * catálogo original (previo a Compras) puede no tener proveedor asignado. Sin este guard,
     * PurchaseOrder::create() lanzaba una excepción de BD sin capturar (500) en vez de un 422.
     */
    public function test_no_se_puede_generar_orden_si_el_producto_no_tiene_proveedor(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto sin proveedor', 'description' => 'Producto sin proveedor',
            'price_full' => 20, 'cost' => 12, 'provider_id' => null, 'is_active' => true,
        ]);
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(422);
    }

    public function test_no_se_puede_generar_orden_dos_veces(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(422);
    }

    /**
     * REQ-184 — Regla de Negocio literal del Excel: margen ≥ umbral (default 15%,
     * `compras_settings.replacement_margin_threshold`) nace "Pendiente", sin escalar a Mark.
     */
    public function test_generar_solicitud_con_margen_suficiente_nace_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct(['price_full' => 100, 'cost' => 80]); // margen 20%
        $salesProject = $this->makeApprovedSalesProject();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('status', ReplacementRequest::STATUS_PENDIENTE);
        $response->assertJsonPath('margin_percent', 20);
        $response->assertJsonPath('sales_project_id', $salesProject->id);
    }

    /** REQ-184 — margen < umbral escala a "Pendiente Gerencia" (notifica a Mark). */
    public function test_generar_solicitud_con_margen_bajo_escala_a_gerencia(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct(['price_full' => 100, 'cost' => 90]); // margen 10% < 15%
        $salesProject = $this->makeApprovedSalesProject();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('status', ReplacementRequest::STATUS_PENDIENTE_GERENCIA);
        $response->assertJsonPath('margin_percent', 10);
    }

    /** REQ-184 — sin costo de referencia no se puede verificar el umbral, default seguro: escalar. */
    public function test_generar_solicitud_sin_costo_del_sustituto_escala_a_gerencia(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct(['price_full' => 100, 'cost' => null]);
        $salesProject = $this->makeApprovedSalesProject();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('status', ReplacementRequest::STATUS_PENDIENTE_GERENCIA);
        $response->assertJsonPath('margin_percent', null);
    }

    /** REQ-184 — cambiar el umbral en Ajustes de Compras cambia el resultado del siguiente cálculo. */
    public function test_generar_solicitud_usa_el_umbral_configurado_en_ajustes(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        ComprasSettings::current()->update(['replacement_margin_threshold' => 5]);

        $original = $this->makeProduct();
        $substitute = $this->makeProduct(['price_full' => 100, 'cost' => 90]); // margen 10%, antes hubiese escalado
        $salesProject = $this->makeApprovedSalesProject();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('status', ReplacementRequest::STATUS_PENDIENTE);
    }

    public function test_generar_solicitud_sin_permiso_write_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(403);
    }

    public function test_generar_solicitud_sin_sales_project_id_retorna_422(): void
    {
        // REQ-184 — 4ta repetición del bug de REQ-118/179/180: el campo debe ser requerido, no
        // opcional, para forzar el picker restringido en vez de dejarlo en blanco.
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct();

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
            ])
            ->assertStatus(422);
    }

    /**
     * REQ-133 RN dura revalidada server-side (Senior Review 20260718) — un sales_project_id real
     * pero sin Pedido Aprobado no debe colarse solo porque el frontend "confía" en su picker.
     */
    public function test_generar_solicitud_con_sales_project_no_aprobado_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.write']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct();
        $salesProject = SalesProject::create(['name' => 'Proyecto Sin Aprobar']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(422);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(0, ReplacementRequest::count());
    }
}
