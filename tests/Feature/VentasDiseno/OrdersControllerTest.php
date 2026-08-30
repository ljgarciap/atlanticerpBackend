<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\OrdersSettings;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\Bodega\Models\Warehouse;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-703→710 (REQ-623→630, Epic CRM Batch F) — "Pedidos". Cubre: RN1 (solo Aprobado),
 * alcance Mías/Equipo (REQ-629), cálculo de margen cotizado/facturado mixto/definitivo (REQ-624),
 * clasificación de Estado con umbral parametrizable (REQ-625), KPIs (REQ-626) y búsqueda/filtro
 * (REQ-627). El "costo facturado real" se resuelve vía Compras (PurchaseOrderLine.sales_project_id
 * + PurchaseOrderReceiptLine confirmada) — ver OrdersService::resolveInvoicedCosts().
 */
class OrdersControllerTest extends FeatureTestCase
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

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    /**
     * Crea un Pedido completo: PipelineCard en "approved" + Quote generada (folio) + 1 partida
     * con los ítems indicados. Cada item: ['price' => .., 'cost' => .., 'qty' => .., 'product' => CatalogProduct|null].
     *
     * @param list<array{price: float, cost: float, qty: float, product?: CatalogProduct|null}> $items
     * @return array{card: PipelineCard, quote: Quote, project: SalesProject}
     */
    private function makeApprovedOrder(int $ownerId, array $items, string $folio = 'COT-1001'): array
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        $card = PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => 'approved',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);

        $quote = Quote::create([
            'owner_id' => $ownerId, 'sales_project_id' => $project->id, 'master_client_id' => $master->id,
            'folio' => $folio, 'generated_at' => Carbon::now(),
        ]);
        $part = $quote->parts()->create(['name' => 'Partida 1']);

        foreach ($items as $item) {
            $part->items()->create([
                'reference'          => $item['product']->reference ?? 'REF-CUSTOM',
                'description'        => 'Item de prueba',
                'quantity'           => $item['qty'],
                'unit_price'         => $item['price'],
                'cost'               => $item['cost'],
                'is_custom'          => ! isset($item['product']),
                'catalog_product_id' => $item['product']->id ?? null,
            ]);
        }

        return ['card' => $card, 'quote' => $quote, 'project' => $project];
    }

    private function makeCatalogProduct(float $cost, string $ref = 'CAT-001'): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => $ref, 'name' => 'Producto de catálogo', 'brand' => 'Test Brand',
            'price_full' => 200.00, 'cost' => $cost, 'stock_quantity' => 5, 'is_active' => true,
        ]);
    }

    /** Simula que Compras ya facturó $unitCost por unidad de $product para el Pedido de $project. */
    private function invoiceProduct(SalesProject $project, CatalogProduct $product, float $unitCost, int $qty = 1): void
    {
        $provider = Provider::create(['name' => 'Proveedor Test', 'currency' => 'USD']);
        $order = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'total_amount' => 0,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'sales_project_id'  => $project->id,
            'quantity'          => $qty, 'unit_cost' => $unitCost, 'subtotal' => $qty * $unitCost,
        ]);
        $receipt = PurchaseOrderReceipt::create(['purchase_order_id' => $order->id]);
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Bodega Central']);
        PurchaseOrderReceiptLine::create([
            'purchase_order_receipt_id' => $receipt->id, 'catalog_product_id' => $product->id,
            'quantity' => $qty, 'unit_cost' => $unitCost, 'warehouse_id' => $warehouse->id,
            'confirmed_at' => now(),
        ]);
    }

    // ── RN1 (REQ-623) — solo cotizaciones Aprobadas ─────────────────────────────

    public function test_solo_lista_pedidos_de_cotizaciones_aprobadas(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $approved = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-APROBADO');

        // Tarjeta en otra etapa (proposal) — NO debe aparecer aunque su Quote tenga folio.
        $master = MasterClient::create(['name' => 'Cliente 2']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Sub 2', 'tax_id' => '155-0000-2-2026']);
        $project2 = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto 2']);
        PipelineCard::create([
            'sales_project_id' => $project2->id, 'stage' => 'proposal',
            'master_client_id' => $master->id, 'sub_client_id' => $sub->id,
            'owner_id' => $designerId, 'stage_changed_at' => Carbon::now(),
        ]);
        Quote::create(['owner_id' => $designerId, 'sales_project_id' => $project2->id, 'folio' => 'COT-PROPUESTA', 'generated_at' => now()]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $folios = collect($response->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains('COT-APROBADO'));
        $this->assertFalse($folios->contains('COT-PROPUESTA'));
    }

    // ── REQ-629 — alcance Mías/Equipo ────────────────────────────────────────────

    public function test_vendedor_solo_ve_sus_propios_pedidos(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-MIO');
        $this->makeApprovedOrder($managerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-AJENO');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $folios = collect($response->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains('COT-MIO'));
        $this->assertFalse($folios->contains('COT-AJENO'));
    }

    public function test_gerencia_ve_los_pedidos_de_todo_el_equipo(): void
    {
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-A');
        $this->makeApprovedOrder($managerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-B');

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders?scope=team')
            ->assertStatus(200);

        $folios = collect($response->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains('COT-A'));
        $this->assertTrue($folios->contains('COT-B'));
    }

    // Pre-QA 2026-07-31 — CRÍTICO real: REQ-629 RN4 dice que Pedidos NO tiene selector Mías/Equipo
    // (el frontend nunca manda `scope`), así que el default del backend cuando el filtro no viene
    // es lo único que determina el alcance real. `test_gerencia_ve_los_pedidos_de_todo_el_equipo`
    // de arriba pasaba `?scope=team` a mano y por eso no detectó que, sin ese parámetro, Gerencia
    // quedaba viendo solo lo suyo — este test replica el request real del frontend (sin `scope`).
    public function test_gerencia_ve_todo_el_equipo_sin_mandar_scope_explicito_como_hace_el_frontend_real(): void
    {
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-SINSCOPE-A');
        $this->makeApprovedOrder($managerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-SINSCOPE-B');

        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $folios = collect($response->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains('COT-SINSCOPE-A'));
        $this->assertTrue($folios->contains('COT-SINSCOPE-B'));
    }

    public function test_pedidos_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('supervisor@illuminations.test', 'Password123!', 'supervisor');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(403);
    }

    public function test_vendedor_no_puede_ver_el_detalle_de_un_pedido_ajeno(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $ajeno = $this->makeApprovedOrder($managerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-AJENO');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/orders/{$ajeno['quote']->id}")
            ->assertStatus(404);
    }

    // ── REQ-624 — margen cotizado/facturado ──────────────────────────────────────

    public function test_margen_cotizado_disponible_de_inmediato_sin_factura(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        // precio 100, costo 60 -> margen 40%.
        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $order['quote']->id);
        $this->assertEquals(40.0, $row['quoted_margin']);
        $this->assertSame('pendiente', $row['estado']);
        $this->assertTrue($row['invoiced_partial']);
    }

    public function test_margen_facturado_mixto_y_parcial_con_productos_pendientes(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $p1 = $this->makeCatalogProduct(60, 'CAT-001');
        $p2 = $this->makeCatalogProduct(60, 'CAT-002');

        $order = $this->makeApprovedOrder($designerId, [
            ['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $p1], // se factura distinto
            ['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $p2], // pendiente
        ]);
        // p1 se compró más caro de lo cotizado: 60 -> 80.
        $this->invoiceProduct($order['project'], $p1, 80);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/orders/{$order['quote']->id}")
            ->assertStatus(200);

        $this->assertTrue($detail->json('invoiced_partial'));
        $this->assertSame(1, $detail->json('pending_count'));
        // margen cotizado total: (200-120)/200*100 = 40. margen facturado mixto: (200-140)/200*100 = 30.
        $this->assertEquals(40.0, $detail->json('quoted_margin'));
        $this->assertEquals(30.0, $detail->json('invoiced_margin'));

        $items = collect($detail->json('items'));
        $pending = $items->firstWhere('pending_purchase', true);
        $this->assertNotNull($pending);
        $this->assertNull($pending['invoiced_cost']);
    }

    public function test_margen_facturado_definitivo_cuando_todos_los_productos_ya_facturaron(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);
        $this->invoiceProduct($order['project'], $product, 60); // exactamente lo cotizado -> sin variación.

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/orders/{$order['quote']->id}")
            ->assertStatus(200);

        $this->assertFalse($detail->json('invoiced_partial'));
        $this->assertSame(0, $detail->json('pending_count'));
        $this->assertEquals(40.0, $detail->json('invoiced_margin'));
        $this->assertSame('igual', $detail->json('estado'));
    }

    public function test_diferencia_de_margen_por_producto_en_puntos_porcentuales(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60); // costo cotizado 60 -> margen 40% a precio 100.

        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);
        $this->invoiceProduct($order['project'], $product, 66); // margen facturado 34% -> diff -6.0 pts.

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/orders/{$order['quote']->id}")
            ->assertStatus(200);

        $item = $detail->json('items.0');
        $this->assertEquals(40.0, $item['quoted_margin']);
        $this->assertEquals(34.0, $item['invoiced_margin']);
        $this->assertEquals(-6.0, $item['margin_diff_pts']);
    }

    // ── REQ-625 — Estado y umbral parametrizable ─────────────────────────────────

    public function test_estado_margen_bajo_cuando_la_caida_supera_el_umbral(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60); // margen cotizado 40%.

        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);
        $this->invoiceProduct($order['project'], $product, 67); // margen facturado 33% -> caída de 7pts > umbral 5pts.

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $order['quote']->id);
        $this->assertSame('bajo', $row['estado']);
    }

    public function test_estado_margen_mejoro_cuando_la_caida_no_supera_el_umbral(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60); // margen cotizado 40%.

        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);
        $this->invoiceProduct($order['project'], $product, 62); // margen facturado 38% -> caída de 2pts < umbral 5pts.

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $order['quote']->id);
        $this->assertSame('mejoro', $row['estado']);
    }

    public function test_umbral_de_margen_bajo_es_parametrizable(): void
    {
        // Baja el umbral a 1pt -> una caída de 2pts (que antes era "mejoró") ahora es "bajó".
        OrdersSettings::current()->update(['margin_drop_threshold_pts' => 1.0]);

        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);
        $this->invoiceProduct($order['project'], $product, 62); // caída de 2pts.

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $order['quote']->id);
        $this->assertSame('bajo', $row['estado']);
    }

    // ── REQ-626 — KPIs ────────────────────────────────────────────────────────────

    public function test_kpis_no_cambian_con_los_filtros_de_busqueda_activos(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-UNO');
        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-DOS');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders?search=UNO')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('summary.total_orders'));
    }

    public function test_margen_facturado_promedio_solo_cuenta_pedidos_100_facturados(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $p1 = $this->makeCatalogProduct(60, 'CAT-A');
        $p2 = $this->makeCatalogProduct(60, 'CAT-B');

        $full = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $p1]], 'COT-FULL');
        $this->invoiceProduct($full['project'], $p1, 60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $p2]], 'COT-PENDIENTE');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('summary.total_orders'));
        $this->assertSame(1, $response->json('summary.fully_invoiced_count'));
        $this->assertEquals(40.0, $response->json('summary.avg_invoiced_margin'));
    }

    // ── REQ-627 — filtro de Estado y búsqueda ─────────────────────────────────────

    public function test_filtro_de_estado_acota_la_tabla(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-PENDIENTE');
        $facturado = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-IGUAL');
        $this->invoiceProduct($facturado['project'], $product, 60);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders?estado=igual')
            ->assertStatus(200);

        $folios = collect($response->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains('COT-IGUAL'));
        $this->assertFalse($folios->contains('COT-PENDIENTE'));
    }

    public function test_busqueda_por_folio_cliente_o_proyecto(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-9001');
        $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]], 'COT-9002');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/orders?search=9001')
            ->assertStatus(200);

        $folios = collect($response->json('data'))->pluck('folio');
        $this->assertTrue($folios->contains('COT-9001'));
        $this->assertFalse($folios->contains('COT-9002'));
    }

    // ── Pre-QA 2026-07-31 — CRÍTICO: costo facturado compartido entre Pedidos ───────
    // Una misma orden de compra puede repartir el mismo producto entre 2 Pedidos distintos.
    // PurchaseOrderReceiptLine no se parte por Pedido, así que atribuirle el costo/estado
    // "facturado" a ambos por igual generaba "Margen bajó" falso. Ver docblock de
    // OrdersService::resolveInvoicedCosts().

    public function test_producto_compartido_entre_2_pedidos_en_la_misma_po_queda_pendiente_para_ambos(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $managerId  = $this->userIdByEmail('management@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $orderA = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 5, 'product' => $product]], 'COT-COMPARTIDO-A');
        $orderB = $this->makeApprovedOrder($managerId, [['price' => 100, 'cost' => 60, 'qty' => 3, 'product' => $product]], 'COT-COMPARTIDO-B');

        // Misma PO, 2 líneas del mismo producto, una por Pedido.
        $provider = Provider::create(['name' => 'Proveedor Compartido', 'currency' => 'USD']);
        $po = PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'total_amount' => 0,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'catalog_product_id' => $product->id,
            'sales_project_id'  => $orderA['project']->id, 'quantity' => 5, 'unit_cost' => 90, 'subtotal' => 450,
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'catalog_product_id' => $product->id,
            'sales_project_id'  => $orderB['project']->id, 'quantity' => 3, 'unit_cost' => 90, 'subtotal' => 270,
        ]);
        $receipt = PurchaseOrderReceipt::create(['purchase_order_id' => $po->id]);
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Bodega Central']);
        PurchaseOrderReceiptLine::create([
            'purchase_order_receipt_id' => $receipt->id, 'catalog_product_id' => $product->id,
            'quantity' => 8, 'unit_cost' => 90, 'warehouse_id' => $warehouse->id, 'confirmed_at' => now(),
        ]);

        $tokenDesigner = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $detailA = $this->withHeaders($this->authHeaders($tokenDesigner))
            ->getJson("/api/ventas-diseno/orders/{$orderA['quote']->id}")
            ->assertStatus(200);

        $tokenManager = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $detailB = $this->withHeaders($this->authHeaders($tokenManager))
            ->getJson("/api/ventas-diseno/orders/{$orderB['quote']->id}")
            ->assertStatus(200);

        // Ninguno de los dos debe quedar marcado como facturado con el costo compartido —
        // ambigüedad real, no se arriesga un número financiero incorrecto.
        $this->assertTrue($detailA->json('invoiced_partial'));
        $this->assertSame(1, $detailA->json('pending_count'));
        $this->assertSame('pendiente', $detailA->json('estado'));
        $this->assertTrue($detailB->json('invoiced_partial'));
        $this->assertSame(1, $detailB->json('pending_count'));
        $this->assertSame('pendiente', $detailB->json('estado'));
    }

    public function test_dos_recepciones_confirmadas_para_el_mismo_producto_usa_la_mas_antigua_de_forma_determinista(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $product = $this->makeCatalogProduct(60);

        $order = $this->makeApprovedOrder($designerId, [['price' => 100, 'cost' => 60, 'qty' => 1, 'product' => $product]]);

        // 2 POs distintas, confirmadas en orden inverso a su creación (la más "antigua" por
        // confirmed_at es la segunda PO creada) — el resultado debe seguir confirmed_at, no el
        // orden de inserción física.
        $provider = Provider::create(['name' => 'Proveedor Determinismo', 'currency' => 'USD']);
        $warehouse = Warehouse::first() ?? Warehouse::create(['name' => 'Bodega Central']);

        $poLater = PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'total_amount' => 0]);
        PurchaseOrderLine::create(['purchase_order_id' => $poLater->id, 'catalog_product_id' => $product->id, 'sales_project_id' => $order['project']->id, 'quantity' => 1, 'unit_cost' => 75, 'subtotal' => 75]);
        $receiptLater = PurchaseOrderReceipt::create(['purchase_order_id' => $poLater->id]);
        PurchaseOrderReceiptLine::create(['purchase_order_receipt_id' => $receiptLater->id, 'catalog_product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 75, 'warehouse_id' => $warehouse->id, 'confirmed_at' => Carbon::now()->addMinute()]);

        $poEarlier = PurchaseOrder::create(['provider_id' => $provider->id, 'currency' => 'USD', 'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(), 'total_amount' => 0]);
        PurchaseOrderLine::create(['purchase_order_id' => $poEarlier->id, 'catalog_product_id' => $product->id, 'sales_project_id' => $order['project']->id, 'quantity' => 1, 'unit_cost' => 65, 'subtotal' => 65]);
        $receiptEarlier = PurchaseOrderReceipt::create(['purchase_order_id' => $poEarlier->id]);
        PurchaseOrderReceiptLine::create(['purchase_order_receipt_id' => $receiptEarlier->id, 'catalog_product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 65, 'warehouse_id' => $warehouse->id, 'confirmed_at' => Carbon::now()]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/orders/{$order['quote']->id}")
            ->assertStatus(200);

        // La PO con confirmed_at más antiguo (poEarlier, costo 65) es la que gana — no depende
        // del orden de inserción física en Postgres.
        $this->assertEquals(65.0, $detail->json('items.0.invoiced_cost'));
    }

    // ── Auth ──────────────────────────────────────────────────────────────────────

    public function test_listar_pedidos_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/ventas-diseno/orders')->assertStatus(401);
    }
}
