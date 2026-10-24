<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
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
    private function makeApprovedSalesProject(string $name = 'Proyecto Test', string $owner = 'designer@atlantic.test'): SalesProject
    {
        $owner = User::where('email', $owner)->firstOrFail();
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
        // REQ-186 Escenario 1. SCRUM-818 (CA13/CA14) — Generar Orden es exclusivo de Líder de
        // Compras por role_key, no alcanza con compras.write — se loguea como Lider Compras (real
        // lider_compras) en vez de designer@ con permisos ad-hoc.
        $original = $this->makeProduct(['description' => 'Bombillo decorativo E27 Ámbar', 'cost' => 12]);
        $substitute = $this->makeProduct(['description' => 'Bombillo genérico E27', 'cost' => 8]);
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $original->id,
            'proposed_catalog_product_id' => $substitute->id,
            'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
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
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_PENDIENTE,
        ]);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
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
        $product = CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto sin proveedor', 'description' => 'Producto sin proveedor',
            'price_full' => 20, 'cost' => 12, 'provider_id' => null, 'is_active' => true,
        ]);
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(422);
    }

    public function test_no_se_puede_generar_orden_dos_veces(): void
    {
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(422);
    }

    // ── SCRUM-818 (CA13/CA14) — exclusividad de Generar Orden por role_key ──────────────────────

    /**
     * Operaciones tiene compras.sustitutos.manage (acceso completo a Comparación de Referencias)
     * pero NUNCA compras.write real — el gate de ruta (permission:compras.write, sin
     * compras.sustitutos.manage a propósito, ver routes/compras.php) ya la bloquea acá; el
     * siguiente test (Gerencia) cubre el caso donde SÍ hay compras.write real y hace falta el
     * chequeo interno de role_key.
     */
    public function test_operaciones_no_puede_generar_orden_pese_a_tener_acceso_completo(): void
    {
        $this->grantCompras('lideroperaciones@test.com', ['compras.sustitutos.manage', 'compras.limited.view']);
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('lideroperaciones@test.com', 'lideroperaciones@test.com', 'lider_operaciones');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(403);
    }

    /** Gerencia tiene compras.write real pero la exclusividad es por role_key, no por permission. */
    public function test_gerencia_no_puede_generar_orden_pese_a_tener_compras_write(): void
    {
        $this->grantCompras('gerencia3@test.com', ['compras.write', 'compras.read', 'gerencia.view']);
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(403);
    }

    /**
     * REQ-184 — Regla de Negocio literal del Excel: margen ≥ umbral (default 15%,
     * `compras_settings.replacement_margin_threshold`) nace "Pendiente", sin escalar a Gerencia.
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

    /** REQ-184 — margen < umbral escala a "Pendiente Gerencia" (notifica a Gerencia). */
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

    /**
     * SCRUM-818 — `makeApprovedSalesProject()` siempre asigna el PipelineCard a designer@, así que
     * este caso usa un proyecto de OTRO responsable (supervisor@): sin compras.write y sin ser el
     * responsable del proyecto, sigue dando 403 — a diferencia de
     * `test_vendedor_responsable_puede_crear_solicitud_sin_permiso_de_compras` (mismo escenario,
     * pero designer SÍ es el responsable).
     */
    public function test_generar_solicitud_sin_permiso_write_ni_ser_responsable_del_proyecto_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject(owner: 'supervisor@atlantic.test');

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(403);
    }

    /** SCRUM-818 (REQ-818 rebote, sección 3.2) — el vendedor/diseñador responsable de SU PROPIO proyecto puede crear la solicitud aunque no tenga ningún permiso de Compras. */
    public function test_vendedor_responsable_puede_crear_solicitud_sin_permiso_de_compras(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct(['price_full' => 100, 'cost' => 80]); // margen 20%
        $salesProject = $this->makeApprovedSalesProject(); // owner por defecto: designer@

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/replacement-requests', [
                'original_catalog_product_id' => $original->id,
                'proposed_catalog_product_id' => $substitute->id,
                'sales_project_id'            => $salesProject->id,
            ])
            ->assertStatus(201);
    }

    /** SCRUM-818 — un vendedor/diseñador sin permiso de Compras no puede crear a nombre de un proyecto ajeno. */
    public function test_vendedor_no_puede_crear_solicitud_para_proyecto_ajeno(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $original = $this->makeProduct();
        $substitute = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject(owner: 'supervisor@atlantic.test');

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

    // ── SCRUM-818 — approve()/reject() y matriz de aprobación por margen ───────────────────────

    /** margen ≥ umbral (pendiente) — el vendedor/diseñador responsable del proyecto SÍ puede aprobar. */
    public function test_vendedor_responsable_puede_aprobar_solicitud_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE, 'margin_percent' => 20,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/approve")
            ->assertStatus(200);

        $response->assertJsonPath('status', ReplacementRequest::STATUS_APROBADA);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('designer@atlantic.test', $request->fresh()->approver?->email);
    }

    /** margen < umbral (pendiente_gerencia) — el vendedor responsable NO puede aprobar, solo Gerencia. */
    public function test_vendedor_responsable_no_puede_aprobar_solicitud_pendiente_gerencia(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE_GERENCIA, 'margin_percent' => 10,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/approve")
            ->assertStatus(403);
    }

    /** Gerencia (permission:gerencia.view) SÍ puede aprobar una pendiente_gerencia. */
    public function test_gerencia_puede_aprobar_solicitud_pendiente_gerencia(): void
    {
        $this->grantCompras('designer@atlantic.test', ['gerencia.view']);
        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject(owner: 'supervisor@atlantic.test');
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE_GERENCIA, 'margin_percent' => 10,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('status', ReplacementRequest::STATUS_APROBADA);
    }

    /** Un vendedor/diseñador ajeno al proyecto no puede decidir ni una pendiente "normal". */
    public function test_vendedor_no_responsable_no_puede_aprobar(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject(owner: 'supervisor@atlantic.test');
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE, 'margin_percent' => 20,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/approve")
            ->assertStatus(403);
    }

    /** Rechazo sigue la misma autorización que aprobación (Escenario 2/4 del ticket). */
    public function test_vendedor_responsable_puede_rechazar_solicitud_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE, 'margin_percent' => 20,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/reject")
            ->assertStatus(200);

        $response->assertJsonPath('status', ReplacementRequest::STATUS_RECHAZADA);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('designer@atlantic.test', $request->fresh()->rejecter?->email);
    }

    /** Una solicitud ya decidida (aprobada/rechazada) no se puede volver a decidir. */
    public function test_no_se_puede_decidir_una_solicitud_ya_decidida(): void
    {
        $this->grantCompras('designer@atlantic.test', ['gerencia.view']);
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_APROBADA,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/approve")
            ->assertStatus(422);
    }

    /** Escenario 3/4 del ticket — el listado en sí ya scopea por proyecto propio para quien no tiene acceso completo a Compras. */
    public function test_vendedor_solo_ve_solicitudes_de_sus_propios_proyectos_en_el_listado(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $ownProject = $this->makeApprovedSalesProject('Proyecto Propio');
        $otherProject = $this->makeApprovedSalesProject('Proyecto Ajeno', owner: 'supervisor@atlantic.test');
        ReplacementRequest::create(['original_catalog_product_id' => $product->id, 'sales_project_id' => $ownProject->id, 'status' => ReplacementRequest::STATUS_PENDIENTE]);
        ReplacementRequest::create(['original_catalog_product_id' => $product->id, 'sales_project_id' => $otherProject->id, 'status' => ReplacementRequest::STATUS_PENDIENTE]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests')
            ->assertStatus(200);

        $projectIds = collect($response->json('data'))->pluck('sales_project_id');
        $this->assertEquals([$ownProject->id], $projectIds->all());
    }

    /** `can_decide` viaja en el JSON, calculado con la misma regla que el endpoint de verdad aplica. */
    public function test_can_decide_refleja_si_el_usuario_actual_puede_decidir(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $ownProject = $this->makeApprovedSalesProject();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $ownProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.can_decide', true);
    }

    // ── SCRUM-818 (rebote 2026-09-03) — reproduce el bug real reportado por Gerencia ────────────

    /**
     * Hallazgo 1 — a diferencia de `test_gerencia_puede_aprobar_solicitud_pendiente_gerencia`
     * (que simula el caso "ya arreglado" otorgando `gerencia.view` manualmente vía
     * `grantCompras()`, un permiso v1), este reproduce la cuenta REAL de Gerencia/Mark: acceso a
     * Compras/Gerencia solo vía su ROL `management` (`RoleModuleVisibility`, v2) — nunca tuvieron
     * `gerencia.view`/`compras.read` cargado en `extra_permissions`. Antes del fix,
     * `hasFullComprasReadAccess()` usaba `$user->hasPermission()` (v1) y los trataba como si no
     * tuvieran ningún acceso — la pestaña "Solicitudes" siempre vacía, aunque hubiera registros
     * reales.
     */
    public function test_gerencia_ve_solicitudes_solo_con_acceso_de_rol_sin_permiso_v1_extra(): void
    {
        $role = Role::where('key', 'management')->firstOrFail();
        RoleModuleVisibility::updateOrCreate(
            ['role_id' => $role->id, 'module' => 'compras'],
            ['can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true],
        );
        RoleModuleVisibility::updateOrCreate(
            ['role_id' => $role->id, 'module' => 'gerencia'],
            ['can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true],
        );

        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject(owner: 'supervisor@atlantic.test');
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE_GERENCIA, 'margin_percent' => 10,
        ]);

        $token = $this->loginAs('gerencia3@test.com', 'gerencia3@test.com', 'management');

        // Antes del fix: lista vacía pese a existir la solicitud (mismo síntoma exacto de Gerencia).
        $indexResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests')
            ->assertStatus(200);
        $ids = collect($indexResponse->json('data'))->pluck('id');
        $this->assertContains($request->id, $ids);

        // Y, ya viéndola, también puede decidirla (gerencia.view real vía rol, no v1).
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/approve")
            ->assertStatus(200)
            ->assertJsonPath('status', ReplacementRequest::STATUS_APROBADA);
    }

    /**
     * Hallazgo 3(b) — antes el frontend mostraba el botón "Generar orden" a cualquiera con la
     * fila visible (sin chequear rol), y el backend recién bloqueaba tras el click. Ahora el
     * backend informa `can_generate_order` explícito por usuario, mismo criterio que `can_decide`
     * — el vendedor/diseñador responsable del proyecto NUNCA debe verlo en true, aunque vea la
     * solicitud Rechazada (Escenario 3/4 del ticket: sigue viendo sus propias solicitudes).
     */
    public function test_can_generate_order_es_false_para_vendedor_responsable_en_solicitud_rechazada(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $product = $this->makeProduct();
        $salesProject = $this->makeApprovedSalesProject();
        ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.can_generate_order', false);
    }

    /** Mismo caso, pero para Líder de Compras (Lider Compras) — la única con `can_generate_order` en true. */
    public function test_can_generate_order_es_true_solo_para_lider_compras(): void
    {
        $product = $this->makeProduct();
        ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.can_generate_order', true);
    }

    /** Ya se generó la orden — `can_generate_order` debe pasar a false aunque sea Líder de Compras. */
    public function test_can_generate_order_es_false_si_ya_se_genero_la_orden(): void
    {
        $product = $this->makeProduct();
        $request = ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'status' => ReplacementRequest::STATUS_RECHAZADA,
        ]);

        $token = $this->loginAs('lidercompras@test.com', 'lidercompras@test.com', 'lider_compras');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/replacement-requests/{$request->id}/generate-order")
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/replacement-requests')
            ->assertStatus(200);

        $response->assertJsonPath('data.0.can_generate_order', false);
    }
}
