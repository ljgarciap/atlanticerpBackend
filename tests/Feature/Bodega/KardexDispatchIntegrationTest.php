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
        $this->grantBodegaPermissions('almacen@illuminations.com.pa');
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        return $this->authHeaders($token);
    }

    public function test_despachar_registra_el_movimiento_de_kardex_con_la_bodega_real_del_picking(): void
    {
        $product = $this->makeCatalogProduct();
        $central = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $this->setStock($product, 15, 'Bodega Central');

        $pickerId = $this->userIdByEmail('apolonio.gonzalez@illuminations.com.pa');
        $courierId = $this->userIdByEmail('arrochagary@gmail.com');
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

        $courierId = $this->userIdByEmail('arrochagary@gmail.com');
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
        $courierId = $this->userIdByEmail('arrochagary@gmail.com');
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
        $courierId = $this->userIdByEmail('arrochagary@gmail.com');
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
     * Senior Review 2026-07-22, Blocker 2 — escenario EXACTO reportado por el Arquitecto:
     * Bodega A tiene 10 unidades, Bodega B tiene 5 (total 15). Pedido 1 pide 10 y completa
     * picking primero → antes del fix, `resolvePrimaryWarehouseId()` elegía "la bodega con más
     * stock" de forma independiente y SIEMPRE devolvía Bodega A para ambos pedidos (10 > 5, sin
     * descontar lo que Pedido 1 ya se llevó) — Pedido 2 (pide 5) completaba picking DESPUÉS,
     * antes de que Pedido 1 despachara, y el algoritmo viejo también le asignaba Bodega A (10 > 5
     * en la lectura cruda de `product_warehouse_stock`, que todavía no se decrementa hasta el
     * despacho). Al despachar ambos, Bodega A terminaba en -5 (Kardex sin guarda contra saldo
     * negativo) y Bodega B se quedaba con sus 5 unidades intactas, nunca tocadas.
     *
     * Con el fix (reparto por-bodega dentro del mismo cálculo de back-allocation, congelando el
     * warehouse_id de items ya pickeados y descontando su consumo real del remanente antes de
     * repartir entre los competidores todavía en plan): Pedido 2 debe terminar con warehouse_id
     * = Bodega B (no A), y ambos despachos deben dejar cada bodega en 0, nunca en negativo.
     */
    public function test_dos_pedidos_consecutivos_en_2_bodegas_no_dejan_stock_negativo(): void
    {
        $product = $this->makeCatalogProduct();
        $bodegaA = Warehouse::where('name', 'Bodega Central')->firstOrFail();
        $bodegaB = Warehouse::where('name', 'Merma')->firstOrFail();
        $this->setStock($product, 10, 'Bodega Central');
        $this->setStock($product, 5, 'Merma');

        $pickerId  = $this->userIdByEmail('apolonio.gonzalez@illuminations.com.pa');
        $courierId = $this->userIdByEmail('arrochagary@gmail.com');
        $headers   = $this->authHeadersForLiderBodega();
        $this->makeCurrentTenant(); // authHeadersForLiderBodega() hace un login HTTP real, que deja el tenant sin "current" al volver.

        // Pedido 1 — pide 10, completa picking primero.
        $order1 = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 10]);
        $item1  = $order1->items->first();
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/assign-picker", ['picker_id' => $pickerId])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/start-picking")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order1->id}/complete-picking", [
            'items' => [['order_item_id' => $item1->id, 'qty_picked' => 10]],
        ])->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame($bodegaA->id, $item1->fresh()->warehouse_id, 'Pedido 1 (10 unidades) debe resolver a Bodega A (10 disponibles).');

        // Pedido 2 — pide 5, completa picking DESPUÉS, ANTES de que Pedido 1 despache (Bodega A
        // en product_warehouse_stock todavía muestra 10, sin decrementar).
        $order2 = $this->makeOrder([], ['catalog_product_id' => $product->id, 'qty_requested' => 5]);
        $item2  = $order2->items->first();
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/assign-picker", ['picker_id' => $pickerId])->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/start-picking")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order2->id}/complete-picking", [
            'items' => [['order_item_id' => $item2->id, 'qty_picked' => 5]],
        ])->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame(
            $bodegaB->id,
            $item2->fresh()->warehouse_id,
            'Blocker 2 — Pedido 2 debe resolver a Bodega B (la A ya está comprometida por Pedido 1), NO a Bodega A.',
        );

        // Despachar ambos.
        foreach ([$order1, $order2] as $order) {
            $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $order->items->first()->id, 'qty_delivered' => $order->items->first()->qty_requested]],
            ])->assertStatus(200);
            $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/assign-courier", ['courier_id' => $courierId])->assertStatus(200);
            $this->makeCurrentTenant();
            Order::find($order->id)->update(['invoice_ready' => true]);
            $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/dispatch")->assertStatus(200);
            $this->makeCurrentTenant();
        }

        $stockA = ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $bodegaA->id)->value('quantity');
        $stockB = ProductWarehouseStock::where('catalog_product_id', $product->id)->where('warehouse_id', $bodegaB->id)->value('quantity');

        $this->assertSame(0, $stockA, 'Bodega A debe quedar exactamente en 0 (nunca negativa).');
        $this->assertSame(0, $stockB, 'Bodega B debe quedar exactamente en 0 (el remanente sí se usó, no queda fantasma).');

        $movement1 = InventoryMovement::where('referencia_entidad_id', $order1->id)->first();
        $movement2 = InventoryMovement::where('referencia_entidad_id', $order2->id)->first();
        $this->assertSame($bodegaA->id, $movement1->warehouse_id);
        $this->assertSame($bodegaB->id, $movement2->warehouse_id);
    }
}
