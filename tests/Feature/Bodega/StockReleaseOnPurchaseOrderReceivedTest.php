<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-406 RN2 (ADR docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md) — cobertura del
 * puente Compras -> Bodega: `App\Modules\Compras\Events\PurchaseOrderReceived` +
 * `App\Modules\Bodega\Listeners\ReleaseOrdersAwaitingStock`. Escenarios exigidos en la sección
 * "Consecuencias" del ADR: cobertura total de un pedido de un solo producto, cobertura parcial de
 * un producto compitiendo entre 2 pedidos (FIFO), y un pedido con ítems de 2 productos distintos
 * donde solo uno llega (no debe liberarse parcialmente). El bridge se ejercita end-to-end vía el
 * flujo HTTP real (`/api/compras/orders/{id}/advance`), mismo criterio que
 * `PurchaseOrderLifecycleTest::test_avanzar_orden_a_recibido_confirma_pendientes_de_ingreso_automaticamente()`
 * — sin `Event::fake()`, para probar el listener real registrado en `AppServiceProvider`.
 */
class StockReleaseOnPurchaseOrderReceivedTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    /** El cliente HTTP de test resetea el tenant "actual" al final de cada request. */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', $email)->update(['extra_permissions' => json_encode($perms)]);
    }

    private function warehouseId(): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');

        return (int) DB::table("{$slug}_bodega.warehouses")->value('id');
    }

    private function makeProduct(string $reference, ?int $stockQuantity = 0): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => $reference, 'name' => "Producto {$reference}", 'description' => "Producto {$reference}",
            'price_full' => 100, 'cost' => 60, 'stock_quantity' => $stockQuantity, 'is_active' => true,
        ]);
    }

    private function makeLocalOrderedPurchaseOrder(int $providerId): PurchaseOrder
    {
        return PurchaseOrder::create([
            'provider_id' => $providerId, 'currency' => 'USD',
            'status' => PurchaseOrder::STATUS_ORDENADO, 'status_changed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeAwaitingStockOrder(array $overrides = []): Order
    {
        return Order::create([
            'order_number' => 'PED-'.uniqid(),
            'order_type'   => Order::ORDER_TYPE_FALTANTE,
            'stage'        => Order::STAGE_ASIGNADO,
            ...$overrides,
        ]);
    }

    private function makeShortageItem(Order $order, int $productId, int $qtyRequested): OrderItem
    {
        return $order->items()->create([
            'catalog_product_id' => $productId,
            'reference_snapshot'  => "REF-{$productId}",
            'description_snapshot' => 'Ítem de prueba',
            'qty_requested'       => $qtyRequested,
            'found_at_location'   => false,
            'shortage_reason'     => OrderItem::SHORTAGE_SIN_STOCK,
        ]);
    }

    /** Avanza una PO local (ORDENADO) hasta RECIBIDO, registrando un ingreso real por la cantidad dada. */
    private function advanceLocalPurchaseOrderToReceived(array $headers, PurchaseOrder $order, int $productId, int $quantity): void
    {
        // ORDENADO -> EN_TRANSITO_LOCAL
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL);

        $this->withHeaders($headers)->postJson('/api/compras/goods-receipts', [
            'purchase_order_id' => $order->id,
            'lines' => [['catalog_product_id' => $productId, 'quantity' => $quantity, 'unit_cost' => 10, 'warehouse_id' => $this->warehouseId()]],
        ])->assertStatus(201);

        // EN_TRANSITO_LOCAL -> RECIBIDO -- acá se dispara PurchaseOrderReceived.
        $this->withHeaders($headers)->patchJson("/api/compras/orders/{$order->id}/advance")
            ->assertStatus(200)->assertJsonPath('status', PurchaseOrder::STATUS_RECIBIDO);
    }

    /**
     * Cobertura total de un pedido de un solo producto: la PO recibida trae exactamente lo que el
     * pedido necesita -- debe liberarse (order_type -> pedido, item.found_at_location -> true,
     * stage sin cambios).
     */
    public function test_libera_pedido_de_un_solo_producto_con_cobertura_total(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read', 'compras.write']);
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD', 'origin' => Provider::ORIGIN_LOCAL]);
        $product  = $this->makeProduct('SCRUM406-A1', 0);

        $order = $this->makeAwaitingStockOrder(['assistant_id' => null]);
        $item  = $this->makeShortageItem($order, $product->id, 5);

        $po = $this->makeLocalOrderedPurchaseOrder($provider->id);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->advanceLocalPurchaseOrderToReceived($this->authHeaders($token), $po, $product->id, 5);

        $this->reMakeCurrent();

        $order->refresh();
        $item->refresh();
        $this->assertSame(Order::ORDER_TYPE_PEDIDO, $order->order_type, 'El pedido debía liberarse (cobertura total).');
        $this->assertSame(Order::STAGE_ASIGNADO, $order->stage, 'El stage NO debe cambiar, solo order_type.');
        $this->assertTrue((bool) $item->found_at_location);
    }

    /**
     * Cobertura parcial de un producto compitiendo entre 2 pedidos: la PO recibida solo trae
     * stock suficiente para UNO de los 2 pedidos que lo necesitan -- gana el más antiguo (FIFO por
     * `updated_at` ascendente), el otro se queda como estaba.
     */
    public function test_stock_insuficiente_para_2_pedidos_libera_solo_el_mas_antiguo_fifo(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read', 'compras.write']);
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD', 'origin' => Provider::ORIGIN_LOCAL]);
        $product  = $this->makeProduct('SCRUM406-A2', 0);

        $older = $this->makeAwaitingStockOrder(['assistant_id' => null]);
        $this->makeShortageItem($older, $product->id, 5);
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_bodega.orders")->where('id', $older->id)->update(['updated_at' => now()->subMinutes(30)]);

        $newer = $this->makeAwaitingStockOrder(['assistant_id' => null]);
        $this->makeShortageItem($newer, $product->id, 5);

        // La PO solo trae 5 unidades -- alcanza para UNO de los 2 pedidos (10 requeridas en total).
        $po = $this->makeLocalOrderedPurchaseOrder($provider->id);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'catalog_product_id' => $product->id,
            'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->advanceLocalPurchaseOrderToReceived($this->authHeaders($token), $po, $product->id, 5);

        $this->reMakeCurrent();

        $older->refresh();
        $newer->refresh();
        $this->assertSame(Order::ORDER_TYPE_PEDIDO, $older->order_type, 'El pedido más antiguo (FIFO) debía liberarse.');
        $this->assertSame(Order::ORDER_TYPE_FALTANTE, $newer->order_type, 'El pedido más nuevo debía quedarse en faltante -- no alcanza el stock.');
    }

    /**
     * Pedido con ítems de 2 productos distintos donde solo uno llega: NO debe liberarse
     * parcialmente -- el pedido completo se queda como estaba hasta que AMBOS productos estén
     * cubiertos.
     */
    public function test_pedido_con_2_productos_faltantes_no_se_libera_si_solo_llega_uno(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read', 'compras.write']);
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD', 'origin' => Provider::ORIGIN_LOCAL]);
        $productA = $this->makeProduct('SCRUM406-A3-PA', 0);
        $productB = $this->makeProduct('SCRUM406-A3-PB', 0);

        $order = $this->makeAwaitingStockOrder(['assistant_id' => null]);
        $itemA = $this->makeShortageItem($order, $productA->id, 3);
        $itemB = $this->makeShortageItem($order, $productB->id, 4);

        // La PO recibida solo cubre el producto A -- el producto B nunca llegó.
        $po = $this->makeLocalOrderedPurchaseOrder($provider->id);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'catalog_product_id' => $productA->id,
            'quantity' => 3, 'unit_cost' => 10, 'subtotal' => 30,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->advanceLocalPurchaseOrderToReceived($this->authHeaders($token), $po, $productA->id, 3);

        $this->reMakeCurrent();

        $order->refresh();
        $itemA->refresh();
        $itemB->refresh();
        $this->assertSame(Order::ORDER_TYPE_FALTANTE, $order->order_type, 'No debe liberarse: el producto B sigue sin stock.');
        $this->assertFalse((bool) $itemA->found_at_location, 'Ningún ítem debe marcarse encontrado -- no hay liberación parcial.');
        $this->assertFalse((bool) $itemB->found_at_location);
    }

    /** Un pedido normal ("pedido"/no faltante) nunca debe tocarse aunque comparta producto. */
    public function test_no_afecta_pedidos_que_no_estan_esperando_stock(): void
    {
        $this->grantCompras('designer@illuminations.test', ['compras.edit', 'compras.read', 'compras.write']);
        $provider = Provider::create(['name' => 'LightCorp', 'currency' => 'USD', 'origin' => Provider::ORIGIN_LOCAL]);
        $product  = $this->makeProduct('SCRUM406-A4', 0);

        $normalOrder = Order::create([
            'order_number' => 'PED-'.uniqid(), 'order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_ASIGNADO,
        ]);
        $normalOrder->items()->create([
            'catalog_product_id' => $product->id, 'reference_snapshot' => 'REF', 'description_snapshot' => 'Ítem',
            'qty_requested' => 2,
        ]);

        $po = $this->makeLocalOrderedPurchaseOrder($provider->id);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id, 'catalog_product_id' => $product->id,
            'quantity' => 2, 'unit_cost' => 10, 'subtotal' => 20,
        ]);

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->advanceLocalPurchaseOrderToReceived($this->authHeaders($token), $po, $product->id, 2);

        $this->reMakeCurrent();

        $normalOrder->refresh();
        $this->assertSame(Order::ORDER_TYPE_PEDIDO, $normalOrder->order_type);
        $this->assertSame(0, NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_STOCK_RELEASED)->count());
    }
}
