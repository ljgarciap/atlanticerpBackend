<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderDocument;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-151→156 (Logística, sprint2, SCRUM-214→219). Recepción por línea/etiqueta (REQ-158),
 * enlace a Ingreso de Mercancía (REQ-157) y publicación de estado (REQ-160) — implementados
 * 2026-07-19/20, ver PurchaseOrder::receptionSummary()/showsGoodsReceiptLink(). Ver registros de
 * ingreso (REQ-159) reusa GoodsReceiptController::index()/show(), ya cubierto en
 * GoodsReceiptControllerTest.
 */
class PurchaseOrderLogisticsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
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

    private function makeOrder(int $providerId, array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $providerId, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            ...$overrides,
        ]);
    }

    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo E27', 'description' => 'Bombillo E27',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function warehouseId(): int
    {
        return (int) DB::table('atlantic_bodega.warehouses')->value('id');
    }

    /** El cliente HTTP de test resetea el tenant "actual" tras cada request (mismo patrón que GoodsReceiptControllerTest). */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── REQ-151: solo envíos activos ──────────────────────────────────────────

    public function test_active_shipments_excluye_ordenes_por_aprobar(): void
    {
        // REQ-151 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?active_shipments=1')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.status', PurchaseOrder::STATUS_EN_TRANSITO);
    }

    public function test_active_shipments_incluye_recibido(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_RECIBIDO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?active_shipments=1')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    // ── REQ-152: chip Retrasados reusa el criterio de REQ-139 ─────────────────

    public function test_chip_critical_funciona_igual_para_retrasados(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO,
            'estimated_arrival_date' => now()->subDay()->toDateString(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?active_shipments=1&chip=critical')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
    }

    /**
     * SCRUM-215 — hallazgo de QA (Gerencia, 2026-08-04): un envío "Ordenado" con llegada
     * estimada vencida no aparecía como retrasado. El único test previo de este chip usaba
     * status=en_transito; este cierra el gap de cobertura para "ordenado" específicamente.
     * Verificado manualmente (tinker, sesión 2026-08-05) que isCritical() ya evalúa esto
     * correctamente — este test deja la cobertura permanente en vez de solo el hallazgo puntual.
     */
    public function test_chip_critical_incluye_ordenado_con_llegada_vencida(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_ORDENADO,
            'estimated_arrival_date' => now()->subMonth()->toDateString(),
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?active_shipments=1&chip=critical')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.status', PurchaseOrder::STATUS_ORDENADO);
    }

    // ── REQ-151 (SCRUM-214): buscador de texto libre también matchea proyecto ────

    public function test_search_matchea_por_nombre_de_proyecto_asignado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $masterClient = \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'MC Test']);
        $subClient = \App\Modules\VentasDiseno\Models\SubClient::create([
            'master_client_id' => $masterClient->id, 'business_name' => 'SC Test', 'tax_id' => 'TAX-1',
        ]);
        $salesProject = \App\Modules\VentasDiseno\Models\SalesProject::create([
            'sub_client_id' => $subClient->id, 'name' => 'Torre Norte Buscable',
        ]);
        $order = $this->makeOrder($provider->id);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10, 'sales_project_id' => $salesProject->id,
        ]);
        $this->makeOrder($provider->id); // orden sin proyecto — no debe matchear

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders?search=Torre+Norte')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $order->id);
    }

    // ── REQ-151 (SCRUM-214, hallazgo Pre-QA 2026-08-05) — shipment-projects, sin gate de cotización aprobada ──

    /**
     * El "Filtro de Proyecto" de Logística usaba approved-projects (gateado por
     * documentStatus()==='approved'), invisible para un proyecto con envío activo real pero sin
     * cotización aprobada vigente. shipment-projects busca sobre las líneas de órdenes en
     * movimiento directamente, sin ese gate — este es el caso que approved-projects fallaba.
     */
    public function test_shipment_projects_encuentra_proyecto_sin_cotizacion_aprobada(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $masterClient = \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'MC Sin Cotizacion']);
        $subClient = \App\Modules\VentasDiseno\Models\SubClient::create([
            'master_client_id' => $masterClient->id, 'business_name' => 'SC Sin Cotizacion', 'tax_id' => 'TAX-SC',
        ]);
        // A propósito SIN Quote/PipelineCard — mismo caso que datos legados o corrección de
        // cotización, la project NUNCA pasa por documentStatus()==='approved'.
        $salesProject = \App\Modules\VentasDiseno\Models\SalesProject::create([
            'sub_client_id' => $subClient->id, 'name' => 'Torre Sin Cotizacion Aprobada',
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10,
            'sales_project_id' => $salesProject->id,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        // Prueba negativa primero: approved-projects (el endpoint viejo) NO lo encuentra — así se
        // reprodujo el hallazgo de Pre-QA en vivo.
        $oldEndpoint = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/approved-projects?search=Sin+Cotizacion')
            ->assertStatus(200);
        $this->assertCount(0, $oldEndpoint->json('data'));

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders/shipment-projects?search=Sin+Cotizacion')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.sales_project_id', $salesProject->id);
        $response->assertJsonPath('data.0.project_name', 'Torre Sin Cotizacion Aprobada');
    }

    /**
     * Un proyecto cuya única orden sigue "Por aprobar" no es un envío activo — no debe aparecer
     * en el filtro de Logística, que manda `active_shipments=1` (LogisticsPage.tsx).
     */
    public function test_shipment_projects_excluye_proyecto_de_orden_no_activa_con_active_shipments(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $masterClient = \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'MC Por Aprobar']);
        $subClient = \App\Modules\VentasDiseno\Models\SubClient::create([
            'master_client_id' => $masterClient->id, 'business_name' => 'SC Por Aprobar', 'tax_id' => 'TAX-PA',
        ]);
        $salesProject = \App\Modules\VentasDiseno\Models\SalesProject::create([
            'sub_client_id' => $subClient->id, 'name' => 'Torre Todavia No Aprobada',
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10,
            'sales_project_id' => $salesProject->id,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders/shipment-projects?search=Todavia+No+Aprobada&active_shipments=1')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
    }

    /**
     * SCRUM-733 (hallazgo Pre-QA 2026-08-05, mismo gap gemelo de SCRUM-214) — Ver Órdenes
     * (OrdersPage.tsx) NO manda `active_shipments`, a propósito: a diferencia de Logística, esa
     * pantalla lista órdenes en cualquier estado, así que el proyecto de una orden "Por aprobar"
     * tiene que seguir siendo encontrable en su filtro.
     */
    public function test_shipment_projects_incluye_proyecto_de_orden_por_aprobar_sin_active_shipments(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $masterClient = \App\Modules\VentasDiseno\Models\MasterClient::create(['name' => 'MC Por Aprobar Ver Ordenes']);
        $subClient = \App\Modules\VentasDiseno\Models\SubClient::create([
            'master_client_id' => $masterClient->id, 'business_name' => 'SC Por Aprobar Ver Ordenes', 'tax_id' => 'TAX-PAVO',
        ]);
        $salesProject = \App\Modules\VentasDiseno\Models\SalesProject::create([
            'sub_client_id' => $subClient->id, 'name' => 'Torre Ver Ordenes Por Aprobar',
        ]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_POR_APROBAR]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'quantity' => 1, 'unit_cost' => 10, 'subtotal' => 10,
            'sales_project_id' => $salesProject->id,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders/shipment-projects?search=Ver+Ordenes+Por+Aprobar')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.sales_project_id', $salesProject->id);
    }

    public function test_shipment_projects_sin_permiso_read_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/orders/shipment-projects?search=x')
            ->assertStatus(403);
    }

    // ── SCRUM-371 (REQ-301) — bodega.read también puede leer el detalle ──────

    public function test_bodega_read_puede_ver_detalle_de_orden_sin_compras_read(): void
    {
        $this->grantCompras('designer@atlantic.test', ['bodega.read']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200);
    }

    public function test_sin_compras_read_ni_bodega_read_detalle_de_orden_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(403);
    }

    // ── REQ-145/158 — Recepción por línea y consolidada ───────────────────────

    public function test_reception_status_pendiente_sin_ingresos(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider->id);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 100, 'unit_cost' => 10, 'subtotal' => 1000]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200);

        $response->assertJsonPath('reception_status', PurchaseOrder::RECEPTION_PENDIENTE);
        $response->assertJsonPath('lines.0.reception_status', PurchaseOrder::RECEPTION_PENDIENTE);
        $response->assertJsonPath('lines.0.received_quantity', 0);
    }

    public function test_reception_status_completo_cuando_cubre_lo_esperado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
        ])->assertStatus(201);

        $this->reMakeCurrent();
        $response = $this->withHeaders($headers)->getJson('/api/compras/orders?search='.$order->id)->assertStatus(200);
        $response->assertJsonPath('data.0.reception_status', PurchaseOrder::RECEPTION_COMPLETO);
    }

    public function test_reception_status_parcial_mezcla_lineas(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $productA = $this->makeProduct(['reference' => 'REC-A']);
        $productB = $this->makeProduct(['reference' => 'REC-B']);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $productA->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $productB->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        // Solo A completo (10 de 10); B queda sin ningún ingreso registrado (pendiente).
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $productA->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
        ])->assertStatus(201);

        $this->reMakeCurrent();
        $response = $this->withHeaders($headers)->getJson("/api/compras/orders/{$order->id}")->assertStatus(200);
        $response->assertJsonPath('reception_status', PurchaseOrder::RECEPTION_PARCIAL);
    }

    /** REQ-145 RN — un movimiento "Por ingresar" (sin confirmar) cuenta igual para Recepción, a diferencia de Disponible en Inventario. */
    public function test_reception_cuenta_lineas_por_ingresar_sin_confirmar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        // Sin confirmar (confirm-pending nunca se llama) — queda "Por ingresar" en Inventario.
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
        ])->assertStatus(201);

        $this->reMakeCurrent();
        $response = $this->withHeaders($headers)->getJson("/api/compras/orders/{$order->id}")->assertStatus(200);
        $response->assertJsonPath('reception_status', PurchaseOrder::RECEPTION_COMPLETO);
    }

    // ── REQ-157 — enlace "Ingreso de mercancía", regla de visibilidad ────────

    public function test_enlace_ingreso_mercancia_visible_en_aduana_proveedor_no_local(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_ADUANA]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('shows_goods_receipt_link', true);
    }

    public function test_enlace_ingreso_mercancia_oculto_antes_de_aduana(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('shows_goods_receipt_link', false);
    }

    /** Proveedor local salta "En aduana" (statusSequence()) — el enlace debe aparecer igual desde "En tránsito local". */
    public function test_enlace_ingreso_mercancia_visible_en_transito_local_proveedor_local(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('shows_goods_receipt_link', true);
    }

    /** REQ-157 RN — un remanente pendiente en una etapa anterior oculta el enlace de nuevo, aunque el `status` de la orden ya haya avanzado. */
    public function test_enlace_ingreso_mercancia_oculto_por_remanente_en_etapa_anterior(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        $order = $this->makeOrder($provider->id, [
            'status' => PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}")
            ->assertStatus(200)
            ->assertJsonPath('shows_goods_receipt_link', false);
    }

    // ── REQ-156: contenedor/naviera, editable sin importar el estado ──

    public function test_actualizar_datos_de_envio_en_orden_en_transito(): void
    {
        // REQ-156 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/shipping-info", [
                'container_number' => 'MSCU1234567',
                'carrier' => 'Maersk',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('container_number', 'MSCU1234567');
        $response->assertJsonPath('carrier', 'Maersk');
    }

    /**
     * REQ-153/154 RN3/RN4 (SCRUM-216, corrección 2026-08-05) — `actual_arrival_date` ("Llegada
     * real") ya NO es aceptado por este endpoint, ni siquiera si el cliente lo manda: el fix del
     * mismo día (commit f5b42a6) lo había dejado editable a mano por una lectura incorrecta de
     * REQ-156 (su AC real es sobre contenedor/naviera, nunca mencionó "Llegada real"). Ahora se
     * auto-llena SOLO desde `advance()` al alcanzar "Recibido" — ver
     * PurchaseOrderLifecycleTest::test_avanzar_a_recibido_autollena_llegada_real().
     */
    public function test_shipping_info_ignora_actual_arrival_date_si_se_manda(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/shipping-info", [
                'container_number'    => 'MSCU1234567',
                'actual_arrival_date' => '2026-08-05',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('container_number', 'MSCU1234567');
        $response->assertJsonPath('actual_arrival_date', null);
    }

    // ── REQ-155: checklist de documentos ───────────────────────────────────────

    public function test_subir_documento_del_envio(): void
    {
        // REQ-155 Escenario 1.
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_ADUANA]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => PurchaseOrderDocument::CATEGORY_DECLARACION_NACIONALIZACION,
                'file' => UploadedFile::fake()->create('declaracion.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(201);

        $response->assertJsonPath('category', PurchaseOrderDocument::CATEGORY_DECLARACION_NACIONALIZACION);
        $this->assertNotEmpty($response->json('url'));
    }

    public function test_documento_subido_queda_consultable(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit', 'compras.read']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_ADUANA]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => PurchaseOrderDocument::CATEGORY_FACTURA_COMERCIAL,
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.category', PurchaseOrderDocument::CATEGORY_FACTURA_COMERCIAL);
    }

    public function test_subir_documento_con_categoria_invalida_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.edit']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => 'no_existe',
                'file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    // ── Cobertura de permisos negativos (SCRUM-218, hallazgo MEDIO Pre-QA 2026-07-16) ──────────

    public function test_actualizar_datos_de_envio_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_TRANSITO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/compras/orders/{$order->id}/shipping-info", [
                'container_number' => 'MSCU1234567',
                'carrier' => 'Maersk',
                'actual_arrival_date' => '2026-08-05',
            ])
            ->assertStatus(403);
    }

    public function test_subir_documento_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_ADUANA]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/orders/{$order->id}/documents", [
                'category' => PurchaseOrderDocument::CATEGORY_FACTURA_COMERCIAL,
                'file' => UploadedFile::fake()->create('factura.pdf', 50, 'application/pdf'),
            ])
            ->assertStatus(403);
    }

    public function test_listar_documentos_sin_permiso_read_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $provider = $this->makeProvider();
        $order = $this->makeOrder($provider->id, ['status' => PurchaseOrder::STATUS_EN_ADUANA]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/orders/{$order->id}/documents")
            ->assertStatus(403);
    }
}
