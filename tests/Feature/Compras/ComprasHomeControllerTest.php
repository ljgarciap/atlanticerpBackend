<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Models\User;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\ReplacementRequest;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-175→182 (REQ-111→118) — Inicio de Compras. */
class ComprasHomeControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // SCRUM-177 — summary() ahora llama a OutlookCalendarService (events_today_count).
        $this->fakeOutlookCalendar();
    }

    /** El cliente HTTP de test resetea el tenant "actual" al final de cada request. */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['compras.read', 'compras.write'])]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'Proveedor Demo A', 'currency' => 'USD', ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Artículo E27', 'description' => 'Artículo E27',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 10, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeOrder(int $providerId, array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $providerId, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            'total_amount' => 100, ...$overrides,
        ]);
    }

    public function test_summary_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/compras/home/summary')->assertStatus(401);
    }

    public function test_summary_sin_permiso_read_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(403);
    }

    public function test_resumen_mes_comprado_incluye_todas_las_ordenes_del_mes_sin_importar_estado(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR, 'total_amount' => 500]);
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_RECIBIDO, 'total_amount' => 300]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $response->assertJsonPath('resumen_mes.comprado_mes', 800);
    }

    public function test_resumen_mes_entregado_a_tiempo_null_sin_ordenes_recibidas_este_mes(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $response->assertJsonPath('resumen_mes.entregado_a_tiempo_percent', null);
    }

    public function test_resumen_mes_entregado_a_tiempo_calcula_porcentaje_real(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        // A tiempo: llego el mismo dia estimado.
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_RECIBIDO, 'status_changed_at' => now(),
            'estimated_arrival_date' => now()->subDays(1), 'actual_arrival_date' => now()->subDays(1),
        ]);
        // Tarde: llego despues de lo estimado.
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_RECIBIDO, 'status_changed_at' => now(),
            'estimated_arrival_date' => now()->subDays(5), 'actual_arrival_date' => now()->subDays(1),
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $response->assertJsonPath('resumen_mes.entregado_a_tiempo_percent', 50);
    }

    public function test_pendientes_orden_3_dias_exactos_genera_alerta(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR,
            'status_changed_at' => now()->subDays(3),
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $pendientes = $response->json('pendientes');
        $this->assertCount(1, $pendientes);
        $this->assertSame('orden_sin_aprobar', $pendientes[0]['type']);
    }

    public function test_pendientes_orden_antes_de_3_dias_no_genera_alerta(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR,
            'status_changed_at' => now()->subDays(2),
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $this->assertCount(0, $response->json('pendientes'));
    }

    public function test_ordenes_criticas_coincide_con_chip_criticas_de_ver_ordenes(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_POR_APROBAR, 'status_changed_at' => now()->subDays(4),
        ]);
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO, 'estimated_arrival_date' => now()->subDays(1),
        ]);
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]); // no critica

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $home = $this->withHeaders($headers)->getJson('/api/compras/home/summary')->assertStatus(200);
        $chip = $this->withHeaders($headers)->getJson('/api/compras/orders?chip=critical')->assertStatus(200);

        $this->assertCount(2, $home->json('ordenes_criticas'));
        $this->assertCount(2, $chip->json('data'));
        $this->assertSame(
            collect($home->json('ordenes_criticas'))->pluck('order_id')->sort()->values()->all(),
            collect($chip->json('data'))->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_estado_compras_agrupa_las_7_etapas_con_conteo_y_valor(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO, 'total_amount' => 100]);
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO, 'total_amount' => 200]);
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_RECIBIDO, 'total_amount' => 50]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $stages = collect($response->json('estado_compras'))->keyBy('stage');
        $this->assertCount(7, $stages);
        $this->assertSame(2, $stages[PurchaseOrder::STATUS_ORDENADO]['count']);
        $this->assertEquals(300.0, $stages[PurchaseOrder::STATUS_ORDENADO]['amount']);
        $this->assertSame(1, $stages[PurchaseOrder::STATUS_RECIBIDO]['count']);
        $this->assertSame(0, $stages[PurchaseOrder::STATUS_EN_ADUANA]['count']);
    }

    public function test_por_reordenar_producto_sin_orden_activa_aparece(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $this->makeProduct(['reference' => 'REORDER-1', 'stock_quantity' => 2, 'reorder_point' => 5]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $refs = collect($response->json('por_reordenar'))->pluck('reference');
        $this->assertTrue($refs->contains('REORDER-1'));
    }

    public function test_por_reordenar_producto_con_orden_activa_no_aparece(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['reference' => 'REORDER-2', 'stock_quantity' => 2, 'reorder_point' => 5]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 5, 'subtotal' => 50,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $refs = collect($response->json('por_reordenar'))->pluck('reference');
        $this->assertFalse($refs->contains('REORDER-2'));
    }

    public function test_por_reordenar_se_libera_cuando_la_orden_llega_a_recibido(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['reference' => 'REORDER-3', 'stock_quantity' => 2, 'reorder_point' => 5]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 5, 'subtotal' => 50,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $before = $this->withHeaders($headers)->getJson('/api/compras/home/summary')->assertStatus(200);
        $this->assertFalse(collect($before->json('por_reordenar'))->pluck('reference')->contains('REORDER-3'));

        $this->reMakeCurrent();
        $order->update(['status' => PurchaseOrder::STATUS_RECIBIDO]);

        $after = $this->withHeaders($headers)->getJson('/api/compras/home/summary')->assertStatus(200);
        $this->assertTrue(collect($after->json('por_reordenar'))->pluck('reference')->contains('REORDER-3'));
    }

    public function test_por_reordenar_expone_cantidad_sugerida_y_proveedor(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider(['name' => 'ProveedorSugerido']);
        $this->makeProduct([
            'reference' => 'REORDER-4', 'stock_quantity' => 2, 'reorder_point' => 5,
            'provider_id' => $provider->id, 'cost' => 15,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/summary')->assertStatus(200);

        $item = collect($response->json('por_reordenar'))->firstWhere('reference', 'REORDER-4');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['suggested_quantity']); // 5 - 2
        $this->assertSame('ProveedorSugerido', $item['provider_name']);
    }

    /**
     * SCRUM-908 — tarjeta "Aprobaciones Pendientes". Senior Review: la autorización real de
     * `purchase_order` es `AprobacionGate::esAprobador()` (`ComprasSettings.primary_approver_user_id`),
     * no el permiso `compras.approve` (ese es solo el gate grueso de la ruta —
     * `PurchaseOrderController::approve()` igual exige ser Gerencia, ver docblock de `AprobacionGate`).
     * Este test fija a designer como `primary_approver_user_id` explícitamente para no depender del
     * fallback "sin approver configurado, no bloquea a nadie" de `esAprobador()`. Solo cuentan las
     * órdenes con `requires_primary_approval = true` (RN3/REQ-118) — sin ese flag, `PurchaseOrderController::
     * approve()` las rechaza con 422 aunque el usuario sí sea Gerencia.
     */
    public function test_approvals_pending_incluye_orden_por_aprobar_para_mark(): void
    {
        $designer = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        ComprasSettings::current()->update(['primary_approver_user_id' => $designer->id]);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR, 'total_amount' => 250, 'requires_primary_approval' => true]);
        // Control negativo: una orden que NO requiere aprobación de Gerencia no debe aparecer.
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR, 'total_amount' => 90, 'requires_primary_approval' => false]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/approvals-pending')->assertStatus(200);

        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame('purchase_order', $items[0]['tipo']);
        $this->assertStringStartsWith('/compras/ordenes/', (string) $items[0]['detail_route']);
    }

    public function test_approvals_pending_vacio_sin_ningun_permiso_de_aprobacion(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR, 'total_amount' => 250]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/approvals-pending')->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    /**
     * Senior Review SCRUM-908 — regresión real: la ruta llegó a tener `permission:compras.read`,
     * lo que devolvía 403 para un Vendedor/Diseñador responsable de proyecto que SÍ puede decidir
     * `replacement_request` vía `ventas_diseno.edit` (ver ReplacementRequestController::canDecide()),
     * sin tener ningún permiso de Compras — nunca vería su propia aprobación pendiente. Sin ningún
     * `extra_permissions` de Compras (a diferencia de los otros tests de esta clase, que llaman
     * `grantCompras()`), solo el rol `designer` base.
     */
    public function test_approvals_pending_incluye_sustitucion_para_vendedor_responsable_sin_permiso_de_compras(): void
    {
        $product = $this->makeProduct();
        $owner = User::where('email', 'vendedor@atlantic.test')->firstOrFail();
        $salesProject = SalesProject::create(['name' => 'Proyecto Responsable']);
        Quote::create([
            'sales_project_id' => $salesProject->id, 'owner_id' => $owner->id,
            'folio' => 'COT-'.random_int(1000, 9999), 'confirmed_at' => now(),
        ]);
        PipelineCard::create(['sales_project_id' => $salesProject->id, 'stage' => 'approved', 'owner_id' => $owner->id]);
        ReplacementRequest::create([
            'original_catalog_product_id' => $product->id, 'sales_project_id' => $salesProject->id,
            'status' => ReplacementRequest::STATUS_PENDIENTE, 'margin_percent' => 20,
        ]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/approvals-pending')->assertStatus(200);

        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame('replacement_request', $items[0]['tipo']);
    }

    /**
     * Senior Review SCRUM-908 — bug real encontrado escribiendo este test: la query original
     * consultaba `PurchaseOrderPayment::whereNotNull('pending_amount_change')`, columna que solo
     * existe en `purchase_orders` (seteada por `PurchaseOrderPaymentController::requestAmountChange()`)
     * — rompía con "column does not exist" para cualquier usuario real con `compras.edit`. Corregido
     * a `PurchaseOrder::whereNotNull(...)`.
     */
    public function test_approvals_pending_incluye_cambio_de_monto_para_quien_tiene_compras_edit(): void
    {
        $this->grantCompras('vendedor@atlantic.test');
        DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')
            ->update(['extra_permissions' => json_encode(['compras.read', 'compras.edit'])]);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['total_amount' => 500]);
        $order->update(['pending_amount_change' => 1200, 'amount_change_requested_by' => $this->userIdByEmail('vendedor@atlantic.test')]);

        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/home/approvals-pending')->assertStatus(200);

        $items = collect($response->json('data'));
        $this->assertTrue($items->contains(fn (array $i) => $i['tipo'] === 'purchase_order_amount_change'));
    }

    private function userIdByEmail(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }
}
