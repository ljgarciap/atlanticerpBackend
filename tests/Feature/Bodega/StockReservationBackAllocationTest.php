<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use Carbon\Carbon;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-322, "back-allocation") — 2+ pedidos compitiendo por el
 * mismo producto durante picking simultáneo. RN1: prioridad por fecha de entrega comprometida más
 * próxima. RN2: desempate por fecha de creación más antigua. El nombre "back-allocation" implica
 * que la asignación puede recalcularse HACIA ATRÁS cuando un pedido de mayor prioridad entra a
 * competir después que uno de menor prioridad — cubierto explícitamente acá (no solo "primero en
 * pedir, primero servido").
 */
class StockReservationBackAllocationTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function startPicking(Order $order): void
    {
        $pickerId = $this->userIdByEmail('auxiliarbodega@test.com');
        $order->update(['picker_id' => $pickerId, 'stage' => Order::STAGE_PICKING_PENDIENTE]);

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/start-picking")
            ->assertStatus(200);

        $this->makeCurrentTenant();
    }

    /** REQ-322 Escenario 1 — solo hay stock para uno completo, gana la fecha de entrega más próxima. */
    public function test_prioridad_por_fecha_de_entrega_mas_proxima(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 10);

        $urgent = $this->makeOrder(
            ['committed_delivery_date' => Carbon::tomorrow()],
            ['catalog_product_id' => $product->id, 'qty_requested' => 10],
        );
        $later = $this->makeOrder(
            ['committed_delivery_date' => Carbon::today()->addDays(10)],
            ['catalog_product_id' => $product->id, 'qty_requested' => 10],
        );

        // El pedido MENOS urgente inicia picking primero (orden de clic real del asistente).
        $this->startPicking($later);
        $this->startPicking($urgent);

        $this->assertSame(10, OrderItem::where('order_id', $urgent->id)->value('qty_reserved'), 'El pedido urgente debe recibir todo el stock disponible.');
        $this->assertSame(0, OrderItem::where('order_id', $later->id)->value('qty_reserved'), 'Al pedido menos urgente no le queda stock — sigue el flujo normal de faltante en su propio picking.');
    }

    /** REQ-322 Escenario 2 — misma fecha de entrega, gana el pedido creado primero. */
    public function test_desempate_por_fecha_de_creacion(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 5);
        $sameDate = Carbon::tomorrow();

        $older = $this->makeOrder(['committed_delivery_date' => $sameDate], ['catalog_product_id' => $product->id, 'qty_requested' => 5]);
        $older->update(['created_at' => now()->subDay()]);

        $newer = $this->makeOrder(['committed_delivery_date' => $sameDate], ['catalog_product_id' => $product->id, 'qty_requested' => 5]);

        $this->startPicking($newer);
        $this->startPicking($older);

        $this->assertSame(5, OrderItem::where('order_id', $older->id)->value('qty_reserved'), 'El pedido más antiguo gana el desempate.');
        $this->assertSame(0, OrderItem::where('order_id', $newer->id)->value('qty_reserved'));
    }

    /** "Back-allocation" real: un pedido urgente que llega DESPUÉS recupera reserva ya otorgada a uno menos urgente. */
    public function test_pedido_urgente_recupera_reserva_ya_otorgada_a_uno_menos_urgente(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 8);

        $lessUrgent = $this->makeOrder(
            ['committed_delivery_date' => Carbon::today()->addDays(15)],
            ['catalog_product_id' => $product->id, 'qty_requested' => 8],
        );
        $this->startPicking($lessUrgent);
        $this->assertSame(8, OrderItem::where('order_id', $lessUrgent->id)->value('qty_reserved'), 'Sin competencia, se reserva todo.');

        $urgent = $this->makeOrder(
            ['committed_delivery_date' => Carbon::tomorrow()],
            ['catalog_product_id' => $product->id, 'qty_requested' => 8],
        );
        $this->startPicking($urgent);

        $this->assertSame(8, OrderItem::where('order_id', $urgent->id)->value('qty_reserved'), 'El pedido urgente recupera la reserva.');
        $this->assertSame(0, OrderItem::where('order_id', $lessUrgent->id)->value('qty_reserved'), 'Al pedido menos urgente se le retira la reserva (back-allocation).');
    }

    /** Cuando el stock alcanza para ambos, ninguno compite realmente. */
    public function test_stock_suficiente_para_todos_no_genera_competencia(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 20);

        $a = $this->makeOrder(['committed_delivery_date' => Carbon::tomorrow()], ['catalog_product_id' => $product->id, 'qty_requested' => 6]);
        $b = $this->makeOrder(['committed_delivery_date' => Carbon::today()->addDays(5)], ['catalog_product_id' => $product->id, 'qty_requested' => 6]);

        $this->startPicking($a);
        $this->startPicking($b);

        $this->assertSame(6, OrderItem::where('order_id', $a->id)->value('qty_reserved'));
        $this->assertSame(6, OrderItem::where('order_id', $b->id)->value('qty_reserved'));
    }

    /** Un pedido despachado deja de competir — el stock físico ya salió (Kardex), no debe seguir bloqueando reservas. */
    public function test_pedido_ya_despachado_no_compite_por_reserva(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 5);

        $dispatched = $this->makeOrder(
            ['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $this->userIdByEmail('transporte@test.com'), 'invoice_ready' => true],
            ['catalog_product_id' => $product->id, 'qty_requested' => 5],
        );
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/bodega/orders/{$dispatched->id}/dispatch")->assertStatus(200);

        $this->makeCurrentTenant();
        $newOrder = $this->makeOrder(['committed_delivery_date' => Carbon::tomorrow()], ['catalog_product_id' => $product->id, 'qty_requested' => 5]);
        $this->startPicking($newOrder);

        // El pedido despachado ya no aparece en las etapas "en vuelo" — no compite ni bloquea al nuevo.
        $this->assertSame(5, OrderItem::where('order_id', $newOrder->id)->value('qty_reserved'));
    }
}
