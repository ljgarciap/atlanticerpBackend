<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Models\Order;
use App\Modules\Notifications\Models\NotificationSend;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — REQ-318: resolución de la tarjeta de Revisión de Inventario,
 * los 3 escenarios de negocio (todo encontrado / nada encontrado / mixto).
 */
class InventoryReviewFlowTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function makeReviewOrder(int $qty = 4): Order
    {
        return $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => $qty],
        );
    }

    /** RN1 — todo encontrado: la misma tarjeta pasa directo a En picking. */
    public function test_todo_encontrado_pasa_directo_a_en_picking(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => true, 'location' => 'A-05']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('review.stage', Order::STAGE_EN_PICKING)
            ->assertJsonPath('review.order_type', Order::ORDER_TYPE_PEDIDO)
            ->assertJsonPath('new_order', null);
    }

    /** RN1 — "Sí hay" sin indicar dónde se encontró es un 422 (ubicación obligatoria). */
    public function test_encontrado_sin_ubicacion_retorna_422(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => true]],
            ])
            ->assertStatus(422);
    }

    /**
     * RN1 (corrección 2026-08-11, Daniela Amaya, SCRUM-388) — "Sí hay" con una ubicación distinta
     * de la original NUNCA debe sobrescribir `location_snapshot` (la ubicación de origen del
     * producto) — la nueva ubicación confirmada vive aparte en `found_location` +
     * `found_confirmed_by_id`/`found_confirmed_at`, y la Hoja de Picking la expone ya armada como
     * nota vía `OrderItem::foundNote()` ("Nueva ubicación: X — Confirmado por Y").
     */
    public function test_encontrado_preserva_ubicacion_original_y_registra_quien_confirmo(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $item->update(['location_snapshot' => 'A-01-03']);
        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => true, 'location' => 'B-02-05']],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $fresh = $item->fresh();
        $this->assertSame('A-01-03', $fresh->location_snapshot, 'La ubicación original nunca debe pisarse.');
        $this->assertSame('B-02-05', $fresh->found_location);
        $this->assertNotNull($fresh->found_confirmed_by_id);
        $this->assertNotNull($fresh->found_confirmed_at);
        $this->assertStringContainsString('B-02-05', $fresh->foundNote());
        $this->assertStringContainsString('Confirmado por', $fresh->foundNote());

        // La Hoja de Picking del pedido (ahora En picking) expone ambos datos por separado.
        $sheet = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$review->id}/picking-sheet")
            ->assertStatus(200);
        $this->assertSame('A-01-03', $sheet->json('items.0.location'));
        $this->assertSame('B-02-05', $sheet->json('items.0.found_location'));
        $this->assertStringContainsString('B-02-05', $sheet->json('items.0.found_note'));
    }

    /**
     * RN3 (corrección 2026-08-11, SCRUM-388) — el caso mixto también preserva la ubicación
     * confirmada en la tarjeta nueva ("En picking"), no solo en la original.
     */
    public function test_mixto_propaga_ubicacion_confirmada_a_la_tarjeta_nueva(): void
    {
        $review = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 3, 'reference_snapshot' => 'ITEM-A', 'location_snapshot' => 'A-01'],
        );
        $review->items()->create(['qty_requested' => 2, 'reference_snapshot' => 'ITEM-B', 'location_snapshot' => 'C-03']);
        $review->refresh();
        [$itemA, $itemB] = $review->items->all();

        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [
                    ['order_item_id' => $itemA->id, 'found' => true, 'location' => 'D-04'],
                    ['order_item_id' => $itemB->id, 'found' => false],
                ],
            ])
            ->assertStatus(200);

        $newOrderId = $response->json('new_order.id');
        $this->makeCurrentTenant();
        $newItem = Order::find($newOrderId)->items->first();
        $this->assertSame('A-01', $newItem->location_snapshot);
        $this->assertSame('D-04', $newItem->found_location);
        $this->assertNotNull($newItem->found_confirmed_by_id);
    }

    /** RN2 — nada encontrado: la tarjeta se marca Faltante y notifica a Compras + Ventas & Diseño. */
    public function test_nada_encontrado_marca_faltante_y_notifica(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => false]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('review.stage', Order::STAGE_ASIGNADO)
            ->assertJsonPath('review.order_type', Order::ORDER_TYPE_FALTANTE);

        $this->makeCurrentTenant();
        $this->assertTrue($review->fresh()->isAwaitingStock());
        $this->assertNotNull(
            NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE)
                ->where('source_ref', "order:{$review->id}")->first(),
            'Se esperaba notificación de faltante (Compras + Ventas & Diseño).',
        );
    }

    /** RN3 — mixto: se generan 2 tarjetas, ambas vinculadas a la misma familia. */
    public function test_resultado_mixto_genera_2_tarjetas_en_la_misma_familia(): void
    {
        $review = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 3, 'reference_snapshot' => 'ITEM-A'],
        );
        $review->items()->create(['qty_requested' => 2, 'reference_snapshot' => 'ITEM-B']);
        $review->refresh();
        [$itemA, $itemB] = $review->items->all();

        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [
                    ['order_item_id' => $itemA->id, 'found' => true, 'location' => 'C-10'],
                    ['order_item_id' => $itemB->id, 'found' => false],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('review.order_type', Order::ORDER_TYPE_FALTANTE)
            ->assertJsonPath('review.stage', Order::STAGE_ASIGNADO);

        $newOrderId = $response->json('new_order.id');
        $this->assertNotNull($newOrderId);

        $this->makeCurrentTenant();
        $newOrder = Order::find($newOrderId);
        $this->assertSame(Order::STAGE_EN_PICKING, $newOrder->stage);
        $this->assertSame(Order::ORDER_TYPE_PEDIDO, $newOrder->order_type);
        $this->assertSame($review->familyRootId(), $newOrder->familyRootId());
        $this->assertSame(1, $newOrder->items()->count(), 'Solo el ítem encontrado se movió a la nueva tarjeta.');
        $this->assertSame(1, $review->fresh()->items()->count(), 'Solo el ítem no encontrado se queda en la original.');
    }

    /** Pre-QA 2026-07-22 — REQ-318 "PERMISOS: Asistentes y Jefe de Bodega", no Picker ni Repartidor. */
    public function test_resolver_con_rol_no_autorizado_retorna_403(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $this->grantBodegaPermissions('apolonio.gonzalez@atlantic.com.pa');
        $token = $this->loginAs('apolonio.gonzalez@atlantic.com.pa', 'apolonio.gonzalez@atlantic.com.pa', 'ayudante_general_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => true, 'location' => 'A-05']],
            ])
            ->assertStatus(403);
    }

    public function test_resolver_una_tarjeta_que_no_es_revision_de_inventario_retorna_422(): void
    {
        $order = $this->makeOrder(['order_type' => Order::ORDER_TYPE_PEDIDO, 'stage' => Order::STAGE_ASIGNADO]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => true, 'location' => 'X']],
            ])
            ->assertStatus(422);
    }

    /**
     * Cubre el estado FINAL correcto de 2 llamadas secuenciales, no concurrencia real (mismo
     * matiz que `OrderPickingFlowTest::test_iniciar_picking_dos_veces_seguidas_la_segunda_falla()`
     * — ver ese docblock). La verificación de concurrencia real vive en
     * `Tests\Feature\Bodega\Concurrency\ResolveInventoryReviewConcurrencyTest` (Senior Review
     * 2026-07-22, Blocker 1b).
     */
    public function test_resolver_dos_veces_la_segunda_falla(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $this->grantBodegaPermissions('almacen@atlantic.com.pa');
        $token = $this->loginAs('almacen@atlantic.com.pa', 'almacen@atlantic.com.pa', 'lider_bodega');
        $headers = $this->authHeaders($token);
        $payload = ['decisions' => [['order_item_id' => $item->id, 'found' => true, 'location' => 'A-01']]];

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", $payload)->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", $payload)->assertStatus(422);
    }
}
