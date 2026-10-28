<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 (decisión de Luis 2026-07-22) — cierra la nota de diseño
 * pendiente original de `DeliveryService`: `order_items.warehouse_id` se puebla al completar el
 * picking (`PickingService::completePicking()`, resuelto contra `product_warehouse_stock` — no
 * hay selector de bodega en la Hoja de Picking, REQ-315/316) y `DeliveryService::dispatch()`
 * llama a `KardexService::registrar()` (REQ-402 RN2, "Salida desde Pedidos Despachado") con ese
 * `warehouse_id` real.
 *
 * Recorre el flujo COMPLETO real (asignar picker -> iniciar picking -> completar picking ->
 * registrar entrega -> asignar repartidor -> despachar) en vez de fabricar el `Order` a mitad de
 * camino, para probar de punta a punta que `warehouse_id` efectivamente viaja desde el picking
 * hasta el Kardex.
 */
class KardexDispatchIntegrationTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    private function authHeadersForLiderBodega(): array
    {
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        return $this->authHeaders($token);
    }

    public function test_despachar_registra_el_movimiento_de_kardex_con_la_bodega_real_del_picking(): void
    {
        $product = $this->makeCatalogProduct();
        $central = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $this->setStock($product, 15, 'Bodega Central');

        $pickerId = $this->userIdByEmail('auxiliarbodega@test.com');
        $courierId = $this->userIdByEmail('transporte@test.com');
        $order = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 5]);
        $item = $order->items->first();

        $headers = $this->authHeadersForLiderBodega();

        // Asignado -> Picking pendiente -> En picking.
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $pickerId])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/start-picking")->assertStatus(200);

        // Completar picking -> el warehouse_id debe quedar poblado con la bodega real (única con stock).
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
            'items' => [['order_item_id' => $item->id, 'qty_picked' => 5]],
        ])->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame($central->id, $item->fresh()->warehouse_id, 'El picking debe resolver la bodega real del producto.');

        // Packing -> Por despachar.
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
            'items' => [['order_item_id' => $item->id, 'qty_delivered' => 5]],
        ])->assertStatus(200);

        // Por despachar -> Despachado.
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/assign-courier", ['courier_id' => $courierId])->assertStatus(200);
        $this->makeCurrentTenant();
        Order::find($order->id)->update(['invoice_ready' => true]);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_DESPACHADO);

        $this->makeCurrentTenant();

        $movement = InventoryMovement::where('referencia_entidad_tipo', 'PEDIDO')
            ->where('referencia_entidad_id', $order->id)
            ->first();

        $this->assertNotNull($movement, 'Se esperaba un movimiento de Kardex para el pedido despachado.');
        $this->assertSame(InventoryMovement::TIPO_SALIDA, $movement->tipo);
        $this->assertSame(-5, $movement->cantidad, 'Salida: cantidad negativa.');
        $this->assertSame($product->id, $movement->catalog_product_id);
        $this->assertSame($central->id, $movement->warehouse_id);
        $this->assertSame(15, $movement->saldo_inicial);
        $this->assertSame(10, $movement->saldo_resultante);

        $this->assertSame(10, ProductWarehouseStock::where('catalog_product_id', $product->id)
            ->where('warehouse_id', $central->id)->value('quantity'), 'El stock físico debe reducirse de verdad.');
    }

    /** El faltante de un dato (warehouse_id) nunca bloquea el despacho real de la mercancía. */
    public function test_despachar_sin_warehouse_id_no_revienta_solo_loggea_y_sigue(): void
    {
        Log::spy();

        $courierId = $this->userIdByEmail('transporte@test.com');
        $product = $this->makeCatalogProduct();
        // Item con catalog_product_id pero SIN warehouse_id — simula un pedido cuyo picking nunca
        // pasó por completePicking() (ej. datos heredados de antes de este batch).
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => true],
            ['catalog_product_id' => $product->id, 'qty_requested' => 3, 'qty_delivered' => 3, 'warehouse_id' => null],
        );

        $this->withHeaders($this->authHeadersForLiderBodega())
            ->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_DESPACHADO);

        $this->makeCurrentTenant();
        $this->assertSame(0, InventoryMovement::where('referencia_entidad_id', $order->id)->count(), 'Sin warehouse_id no se puede generar el movimiento — pero tampoco debe bloquear el despacho.');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'bodega.order.dispatch-missing-warehouse-for-kardex'
                && $context['order_id'] === $order->id);
    }

    /** Un ítem personalizado (sin catalog_product_id) no genera Kardex — no hay producto que trazar. */
    public function test_despachar_item_personalizado_no_genera_kardex(): void
    {
        $courierId = $this->userIdByEmail('transporte@test.com');
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => true],
            ['catalog_product_id' => null, 'qty_requested' => 2, 'qty_delivered' => 2, 'reference_snapshot' => 'CUSTOM-1'],
        );

        $this->withHeaders($this->authHeadersForLiderBodega())
            ->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame(0, InventoryMovement::where('referencia_entidad_id', $order->id)->count());
    }

    /** No repite un item de OrderItem sin nada entregado (qty_delivered = 0) — nada que mover. */
    public function test_despachar_item_sin_entrega_no_genera_kardex(): void
    {
        $courierId = $this->userIdByEmail('transporte@test.com');
        $product = $this->makeCatalogProduct();
        $warehouse = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => true],
            ['catalog_product_id' => $product->id, 'qty_requested' => 4, 'qty_delivered' => 0, 'warehouse_id' => $warehouse->id],
        );

        $this->withHeaders($this->authHeadersForLiderBodega())
            ->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame(0, InventoryMovement::where('referencia_entidad_id', $order->id)->count());
    }

    /**
     * Senior Review 2026-07-22, Blocker 2 — escenario: Bodega Central tiene 10, Merma tiene 5. Pedido 1 pide 10, Pedido 2 pide 5 y completa el picking
     * ANTES de que Pedido 1 despache (Central en `product_warehouse_stock` todavía muestra 10).
     *
     * SCRUM-1196 CAMBIA el resultado esperado: antes Pedido 2 caía en Merma («la bodega con más remanente»), que es exactamente el bug del ticket (se
     * vendía stock de bodegas que no son de venta). Ahora solo cuenta Bodega Central: Pedido 1 se lleva sus 10, a Pedido 2 no le queda nada que alistar (no
     * puede tomar Merma, ni sobrepasar Central), así que el picking solo se completa con 0 y el resto cae en faltante. Ninguna bodega queda en negativo y
     * Merma queda intacta.
     */
    public function test_dos_pedidos_consecutivos_no_dejan_stock_negativo_y_nunca_usan_merma(): void
    {
        $product = $this->makeCatalogProduct();
        $central = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $merma = Warehouse::where('name', 'Merma')->firstOrFail();
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 5, 'Merma');

        $pickerId = $this->userIdByEmail('auxiliarbodega@test.com');
        $courierId = $this->userIdByEmail('transporte@test.com');
        $headers = $this->authHeadersForLiderBodega();
        $this->makeCurrentTenant(); // authHeadersForLiderBodega() hace un login HTTP real, que deja el tenant sin "current" al volver.

        // Pedido 1 — pide 10, completa picking primero.
        $order1 = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 10]);
        $item1 = $order1->items->first();
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/assign-picker", ['picker_id' => $pickerId])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/start-picking")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/complete-picking", [
            'items' => [['order_item_id' => $item1->id, 'qty_picked' => 10]],
        ])->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame($central->id, $item1->fresh()->warehouse_id, 'Pedido 1 sale de Bodega Central.');

        // Pedido 2 — pide 5. Central ya está comprometida por Pedido 1 y Merma NO es bodega de venta: no hay nada que reservar ni alistar.
        $order2 = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 5]);
        $item2 = $order2->items->first();
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/assign-picker", ['picker_id' => $pickerId])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/start-picking")->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertNull($item2->fresh()->warehouse_id, 'No se le asigna Merma: sin saldo en una bodega de venta no hay bodega.');
        $this->assertSame(0, $item2->fresh()->qty_reserved);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/complete-picking", [
            'items' => [['order_item_id' => $item2->id, 'qty_picked' => 5]],
        ])->assertStatus(422)->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'No hay saldo suficiente'));

        // Alistar 0 (todo a faltante) sí se completa: el resto queda pendiente de compra por el flujo de revisión de inventario.
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/complete-picking", [
            'items' => [['order_item_id' => $item2->id, 'qty_picked' => 0]],
        ])->assertStatus(200);

        // Se despacha solo Pedido 1.
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/register-delivery", [
            'items' => [['order_item_id' => $item1->id, 'qty_delivered' => 10]],
        ])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/assign-courier", ['courier_id' => $courierId])->assertStatus(200);
        $this->makeCurrentTenant();
        Order::find($order1->id)->update(['invoice_ready' => true]);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/dispatch")->assertStatus(200);
        $this->makeCurrentTenant();

        $stockCentral = ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $central->id)->value('quantity');
        $stockMerma = ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $merma->id)->value('quantity');

        $this->assertSame(0, $stockCentral, 'Bodega Central queda exactamente en 0 (nunca negativa).');
        $this->assertSame(5, $stockMerma, 'Merma no es bodega de venta: queda intacta.');
        $this->assertSame(0, InventoryMovement::where('referencia_entidad_id', $order2->id)->count(), 'Pedido 2 no movió stock.');
        $this->assertSame($central->id, InventoryMovement::where('referencia_entidad_id', $order1->id)->first()->warehouse_id);
    }
}
