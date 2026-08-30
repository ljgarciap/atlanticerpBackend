<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/** SCRUM-220→230 (REQ-157/159/161→167/173) — Ingreso de Mercancía. */
class GoodsReceiptControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param list<string> $perms */
    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProvider(array $overrides = []): Provider
    {
        return Provider::create(['name' => 'LightCorp', 'currency' => 'USD', 'origin' => Provider::ORIGIN_INTERNACIONAL, ...$overrides]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Bombillo E27', 'description' => 'Bombillo E27',
            'price_full' => 20, 'cost' => 12, 'stock_quantity' => 0, 'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeOrder(Provider $provider, array $overrides = []): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $provider->id, 'currency' => 'USD', 'total_amount' => 0,
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
            ...$overrides,
        ]);
    }

    private function warehouseId(): int
    {
        return (int) DB::table('atlantic_bodega.warehouses')->value('id');
    }

    /**
     * El cliente HTTP de test resetea el tenant "actual" al final de cada request — hace falta
     * re-establecerlo antes de cualquier consulta Eloquent directa después de una llamada HTTP
     * (mismo patrón ya usado en InventoryControllerTest).
     */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── REQ-162 — buscador de órdenes elegibles ──────────────────────────────

    public function test_buscador_de_ordenes_excluye_recibidas_completas(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_RECIBIDO]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/eligible-orders')
            ->assertStatus(200);

        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertNotContains(PurchaseOrder::STATUS_RECIBIDO, $statuses->all());
    }

    /**
     * SCRUM-347 (rebote QA 2026-08-14, hallazgo "una vez recibida, no hay forma de confirmar
     * tardíamente") — antes del fix de `PurchaseOrderController::advance()`, una orden podía
     * llegar a `STATUS_RECIBIDO` sin una sola línea confirmada, y el filtro viejo del buscador
     * ("status != Recibido") la escondía para siempre, sin ninguna forma de repararla. Debe
     * seguir apareciendo mientras le falte algo por confirmar, aunque su `status` ya diga
     * "Recibido".
     */
    public function test_buscador_de_ordenes_incluye_recibida_incompleta_para_repararla(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_RECIBIDO]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/eligible-orders')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($order->id, $ids->all());
    }

    /**
     * SCRUM-347 seguimiento (rebote QA 2026-08-19) — reproduce el 500 real: una orden Recibida
     * con una línea cuyo producto catalogado fue borrado DESPUÉS de crear la orden queda con
     * catalog_product_id NULL (FK nullOnDelete). isFullyReceived() no debe tirar TypeError al
     * agrupar esa línea — el buscador completo debe seguir respondiendo 200 para el resto de
     * usuarios/órdenes.
     */
    public function test_buscador_de_ordenes_no_rompe_con_producto_de_linea_borrado(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_RECIBIDO]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100,
        ]);
        $product->delete(); // dispara nullOnDelete real en purchase_order_lines.catalog_product_id

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts/eligible-orders')
            ->assertStatus(200);
    }

    public function test_orden_precarga_productos_esperados_con_cantidad_completa(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/compras/goods-receipts/orders/{$order->id}")
            ->assertStatus(200);

        $response->assertJsonPath('products.0.expected_quantity', 120);
        $response->assertJsonPath('products.0.remaining', 120);
    }

    // ── REQ-164 — costeo diferenciado ────────────────────────────────────────

    public function test_proveedor_local_calcula_itbms_por_linea(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_LOCAL]);
        $product = $this->makeProduct(['cost' => 5]);
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create([
            'purchase_order_id' => $order->id, 'catalog_product_id' => $product->id,
            'quantity' => 100, 'unit_cost' => 10, 'subtotal' => 1000,
        ]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'lines' => [[
                    'catalog_product_id' => $product->id, 'quantity' => 100,
                    'unit_cost' => 10, 'warehouse_id' => $this->warehouseId(),
                ]],
            ])
            ->assertStatus(201);

        // ITBMS = 100*10*0.07 = 70
        $response->assertJsonPath('lines.0.itbms_amount', 70);
        // costo efectivo = 10 + 70/100 = 10.7, redondeado a 2 decimales
        $this->reMakeCurrent();
        $this->assertEqualsWithDelta(10.7, (float) CatalogProduct::find($product->id)->cost, 0.001);
    }

    public function test_proveedor_no_local_prorratea_gastos_proporcionalmente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider(['origin' => Provider::ORIGIN_INTERNACIONAL]);
        // productoA vale el doble que productoB (mismo ejemplo del RN: reparto 2:1).
        $productA = $this->makeProduct(['reference' => 'A']);
        $productB = $this->makeProduct(['reference' => 'B']);
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $productA->id, 'quantity' => 20, 'unit_cost' => 10, 'subtotal' => 200]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $productB->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'freight_total'     => 300,
                'lines' => [
                    ['catalog_product_id' => $productA->id, 'quantity' => 20, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()],
                    ['catalog_product_id' => $productB->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()],
                ],
            ])
            ->assertStatus(201);

        $this->reMakeCurrent();
        // SCRUM-237 — el prorrateo ya no se pliega en `cost` (queda como el costo base, sin
        // cambios); se persiste por componente en freight_cost. A vale 200 de 300 total => 2/3 *
        // 300 = 200 / 20 unidades = 10/unidad; B vale 100 de 300 => 1/3 * 300 = 100 / 10 = 10/unidad.
        $freshA = CatalogProduct::find($productA->id);
        $freshB = CatalogProduct::find($productB->id);
        $this->assertEqualsWithDelta(10.0, (float) $freshA->cost, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $freshA->freight_cost, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $freshA->import_cost, 0.01);
        $this->assertEqualsWithDelta(20.0, $freshA->costTotal(), 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $freshB->cost, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $freshB->freight_cost, 0.01);
        $this->assertEqualsWithDelta(20.0, $freshB->costTotal(), 0.01);

        // REQ-164 Escenario 1 (fix Pre-QA 2026-07-20, rechazado por marly: "el reparto
        // proporcional no es observable ni queda registrado por línea") — el TOTAL de flete
        // asignado a CADA línea, tal cual lo pide el criterio literal ("la primera línea recibe
        // $200 de flete y la segunda $100"), debe quedar en la respuesta y persistido por línea.
        $response->assertJsonPath('lines.0.freight_cost_share', 200);
        $response->assertJsonPath('lines.1.freight_cost_share', 100);
        $response->assertJsonPath('lines.0.cost_total', 400); // 20*10 + 200
        $response->assertJsonPath('lines.1.cost_total', 200); // 10*10 + 100
    }

    // ── REQ-165/166 — entrega parcial + efectos completos ────────────────────

    public function test_entrega_incompleta_retorna_detalle_sin_persistir(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('requires_pending_status', true);
        $response->assertJsonPath('partial.0.received', 10);
        $response->assertJsonPath('partial.0.expected', 120);
        $response->assertJsonPath('partial.0.missing', 110);
        $this->reMakeCurrent();
        $this->assertSame(0, PurchaseOrderReceipt::count());
    }

    public function test_entrega_incompleta_con_estado_elegido_persiste_y_marca_por_ingresar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.is_pending', true);
        $this->reMakeCurrent();
        $this->assertSame(PurchaseOrder::REMAINDER_EN_TRANSITO, $order->fresh()->pending_remainder_status);
        // "Por ingresar" — no debe contar todavía como stock confirmado.
        $this->assertSame(0, CatalogProduct::find($product->id)->stock_quantity);
    }

    /**
     * Pre-QA 2026-08-07 (SCRUM-208, hallazgo real del rebote de Daniela Amaya 2026-08-06): las
     * etapas de remanente deben cubrir "desde ordenado en adelante" — antes del fix,
     * `REMAINDER_STATUSES` no incluía `ordenado` y este payload fallaba con 422 de validación.
     */
    public function test_entrega_incompleta_acepta_ordenado_como_etapa_del_remanente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'pending_remainder_status' => PurchaseOrder::REMAINDER_ORDENADO,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.is_pending', true);
        $this->reMakeCurrent();
        $this->assertSame(PurchaseOrder::REMAINDER_ORDENADO, $order->fresh()->pending_remainder_status);
    }

    public function test_entrega_completa_no_requiere_modal_y_confirma_directo_si_orden_ya_recibida(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        // RN de REQ-166 — factura que llega después de que la orden ya estaba Recibida: se
        // confirma de inmediato, sin quedar "Por ingresar".
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_RECIBIDO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 10, 'subtotal' => 500]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $warehouseId = $this->warehouseId();
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('lines.0.is_pending', false);
        $this->reMakeCurrent();
        $this->assertSame(50, CatalogProduct::find($product->id)->stock_quantity);
        $this->assertSame(50, (int) ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouseId)->value('quantity'));
    }

    public function test_no_permite_superar_la_cantidad_total_esperada(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        // Primer ingreso: 100 de 120 (parcial, con estado elegido).
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_ADUANA,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 100, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        // Segundo ingreso: intenta 30 más (sumaría 130, supera 120) — máximo permitido son 20.
        $response = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 30, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(422);

        $response->assertJsonFragment(['message' => 'La cantidad supera lo esperado para este producto según la orden. Máximo permitido para este ingreso: 20 unidades.']);
    }

    public function test_segundo_ingreso_que_completa_el_total_no_muestra_modal_y_limpia_estado_pendiente(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 120, 'unit_cost' => 10, 'subtotal' => 1200]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        $response = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 110, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        $this->assertArrayNotHasKey('requires_pending_status', $response->json());
        $this->reMakeCurrent();
        $this->assertNull($order->fresh()->pending_remainder_status);
    }

    // ── REQ-173 — confirmar un producto "Por ingresar" ───────────────────────

    public function test_confirmar_linea_pendiente_aplica_stock_en_la_bodega_ya_elegida(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201)->json();

        $lineId = $receipt['lines'][0]['id'];
        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$lineId}/confirm")
            ->assertStatus(200)
            ->assertJsonPath('is_pending', false);

        $this->reMakeCurrent();
        $this->assertSame(10, CatalogProduct::find($product->id)->stock_quantity);
        $this->assertSame(10, (int) ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $warehouseId)->value('quantity'));
    }

    /** SCRUM-472 (REQ-402 RN1) — confirmar un ingreso genera la línea "Entrada" del Kardex. */
    public function test_confirmar_linea_pendiente_genera_movimiento_de_entrada_en_el_kardex(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201)->json();

        $lineId = $receipt['lines'][0]['id'];
        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$lineId}/confirm")
            ->assertStatus(200);

        $this->reMakeCurrent();
        $movement = \App\Modules\Bodega\Models\InventoryMovement::where('catalog_product_id', $product->id)->first();
        $this->assertNotNull($movement, 'Confirmar el ingreso debe generar una línea en el Kardex');
        $this->assertSame(\App\Modules\Bodega\Models\InventoryMovement::TIPO_ENTRADA, $movement->tipo);
        $this->assertSame(10, $movement->cantidad);
        $this->assertSame(0, $movement->saldo_inicial);
        $this->assertSame(10, $movement->saldo_resultante);
        $this->assertSame('ORDEN_COMPRA', $movement->referencia_entidad_tipo);
        $this->assertSame($order->id, $movement->referencia_entidad_id);
        $this->assertStringContainsString("Orden #{$order->id}", $movement->referencia_texto);
    }

    /**
     * REQ-173 Escenario 1 — "las unidades aparecen en 'Reserva', sin que se me pregunte la
     * bodega otra vez". El botón "Ingresar a Inventario" del detalle del producto confirma TODAS
     * las líneas pendientes de una vez (acá 2, de 2 ingresos parciales distintos), cada una a la
     * bodega ya elegida en su propio registro.
     */
    public function test_confirmar_pendientes_de_un_producto_va_a_la_bodega_ya_elegida_sin_preguntar(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 30, 'unit_cost' => 10, 'subtotal' => 300]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $centralId = (int) DB::table('atlantic_bodega.warehouses')->where('name', 'Bodega Central')->value('id');
        $mermaId = (int) DB::table('atlantic_bodega.warehouses')->where('name', 'Merma')->value('id');

        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $centralId]],
        ])->assertStatus(201);
        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 10, 'warehouse_id' => $mermaId]],
        ])->assertStatus(201);

        $response = $this->withHeaders($headers)
            ->postJson("/api/compras/goods-receipts/products/{$product->id}/confirm-pending")
            ->assertStatus(200);

        $response->assertJsonPath('confirmed_units', 30);
        $this->reMakeCurrent();
        $this->assertSame(30, CatalogProduct::find($product->id)->stock_quantity);
        $this->assertSame(10, (int) ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $centralId)->value('quantity'));
        $this->assertSame(20, (int) ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $mermaId)->value('quantity'));
    }

    public function test_confirmar_pendientes_sin_lineas_por_ingresar_retorna_422(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $product = $this->makeProduct();
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/compras/goods-receipts/products/{$product->id}/confirm-pending")
            ->assertStatus(422);
    }

    /** Senior Review 20260719 — producto inexistente debe dar 404, no el 422 genérico de "sin pendientes". */
    public function test_confirmar_pendientes_de_producto_inexistente_retorna_404(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts/products/999999/confirm-pending')
            ->assertStatus(404);
    }

    // ── REQ-167 — corregir un ingreso ya guardado ────────────────────────────

    public function test_corregir_ingreso_no_duplica_stock(): void
    {
        // Orden NO Recibida (si lo estuviera, REQ-167 bloquea directamente la corrección — ver
        // el otro test). La línea se confirma a mano (REQ-173) antes de corregir, para poder
        // ejercitar el caso real del AC: "cambiar de 10 a 15 debe reflejar 15, no 25" — una
        // corrección sobre mercancía que YA se había confirmado como stock.
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 15, 'unit_cost' => 10, 'subtotal' => 150]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201)->json();

        $lineId = $receipt['lines'][0]['id'];
        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$lineId}/confirm")->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertSame(10, CatalogProduct::find($product->id)->stock_quantity);

        $this->withHeaders($headers)->putJson("/api/compras/goods-receipts/{$receipt['id']}", [
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 15, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(200);

        // 15, no 25 (10 originales + 15 nuevas sumadas por error).
        $this->reMakeCurrent();
        $this->assertSame(15, CatalogProduct::find($product->id)->stock_quantity);
    }

    public function test_corregir_a_cantidad_menor_reemplaza_y_reduce_stock(): void
    {
        // Hallazgo QA SCRUM-230 (2026-07-20): corregir 8→5 "respondía OK pero no guardaba".
        // La corrección a menos deja la línea incompleta → REQ-165 exige elegir estado del
        // remanente (200 sin persistir hasta que se elija). Este test cubre el ciclo completo:
        // primero el aviso sin persistir, después la corrección real con el estado elegido.
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 8, 'unit_cost' => 10, 'subtotal' => 80]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 8, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201)->json();

        $lineId = $receipt['lines'][0]['id'];
        $this->withHeaders($headers)->postJson("/api/compras/goods-receipts/lines/{$lineId}/confirm")->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertSame(8, CatalogProduct::find($product->id)->stock_quantity);

        // Sin estado del remanente: aviso REQ-165, nada se persiste (esto NO es un guardado).
        $this->withHeaders($headers)->putJson("/api/compras/goods-receipts/{$receipt['id']}", [
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(200)->assertJsonPath('requires_pending_status', true);

        $this->reMakeCurrent();
        $this->assertSame(8, CatalogProduct::find($product->id)->stock_quantity);

        // Con el estado elegido: la corrección reemplaza (5, no 8 ni 13) y el stock baja.
        $this->withHeaders($headers)->putJson("/api/compras/goods-receipts/{$receipt['id']}", [
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertSame(5, CatalogProduct::find($product->id)->stock_quantity);
    }

    public function test_no_se_puede_corregir_ingreso_de_orden_recibida_si_ya_no_es_la_misma_orden(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 15, 'unit_cost' => 10, 'subtotal' => 150]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'pending_remainder_status' => PurchaseOrder::REMAINDER_EN_TRANSITO,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201)->json();

        $this->reMakeCurrent();
        $order->update(['status' => PurchaseOrder::STATUS_RECIBIDO]);

        $this->withHeaders($headers)->putJson("/api/compras/goods-receipts/{$receipt['id']}", [
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 15, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(422);
    }

    // ── REQ-159 — ver registros de ingreso ───────────────────────────────────

    public function test_listar_ingresos_busca_por_producto(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct(['reference' => 'REF-BUSCAR-ESTE']);
        $otherProduct = $this->makeProduct(['reference' => 'REF-OTRO']);
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $otherProduct->id, 'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $warehouseId = $this->warehouseId();

        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $warehouseId]],
        ])->assertStatus(201);

        $response = $this->withHeaders($headers)
            ->getJson('/api/compras/goods-receipts?search=REF-BUSCAR-ESTE')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.purchase_order_id', $order->id);
    }

    public function test_listar_ingresos_expone_editable_segun_estado_de_la_orden(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read', 'compras.edit']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider, ['status' => PurchaseOrder::STATUS_ORDENADO]);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $headers = $this->authHeaders($token);

        $receipt = $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
        ])->assertStatus(201)->json();

        $this->withHeaders($headers)->getJson('/api/compras/goods-receipts')
            ->assertStatus(200)
            ->assertJsonPath('data.0.editable', true);

        $this->reMakeCurrent();
        $order->update(['status' => PurchaseOrder::STATUS_RECIBIDO]);

        $response = $this->withHeaders($headers)
            ->getJson("/api/compras/goods-receipts/{$receipt['id']}")
            ->assertStatus(200);
        $response->assertJsonPath('editable', false);
    }

    public function test_listar_ingresos_sin_permiso_read_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', []);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/goods-receipts')
            ->assertStatus(403);
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_guardar_ingreso_sin_permiso_edit_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);
        $provider = $this->makeProvider();
        $product = $this->makeProduct();
        $order = $this->makeOrder($provider);
        PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'subtotal' => 100]);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/compras/goods-receipts', [
                'purchase_order_id' => $order->id,
                'lines' => [['catalog_product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
            ])
            ->assertStatus(403);
    }
}
