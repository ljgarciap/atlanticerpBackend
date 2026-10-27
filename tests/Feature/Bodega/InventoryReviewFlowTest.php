<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\VentasDiseno\Models\Quote;
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
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $item->id, 'found' => true]],
            ])
            ->assertStatus(422);
    }

    /**
     * RN1 (corrección 2026-08-11, Gerencia Test, SCRUM-388) — "Sí hay" con una ubicación distinta
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
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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
        // `items()` no tiene orderBy propio — Postgres no garantiza orden de fila sin él, así que
        // destructurar `->all()` asumiendo orden de inserción es flaky bajo la suite completa
        // (confirmado: 5/5 en aislado, falla intermitente corriendo los 3353 tests). Se busca cada
        // ítem por su `reference_snapshot`, determinista sin importar el orden físico de fila.
        $itemA = $review->items->firstWhere('reference_snapshot', 'ITEM-A');
        $itemB = $review->items->firstWhere('reference_snapshot', 'ITEM-B');

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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

    // SCRUM-1153 — Compras (Lider Compras) recibe el aviso de sin stock con el producto que falta y un enlace a Compras → Inventario
    // con ese producto ya buscado (antes: sin enlace, clic = nada). Ventas & Diseño conserva su aviso de siempre.
    public function test_aviso_de_sin_stock_a_compras_lleva_el_producto_y_el_enlace_a_inventario(): void
    {
        $review = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 4, 'reference_snapshot' => 'ML-10097BWH-1'],
        );
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $review->items->first()->id, 'found' => false]],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $yirenaId = (int) User::where('email', 'lidercompras@test.com')->value('id');
        $send = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE)
            ->where('source_ref', "order:{$review->id}")
            ->get()->first(fn (NotificationSend $n): bool => in_array($yirenaId, $n->audience, true));

        $this->assertNotNull($send, 'Lider Compras (lider_compras) recibe el aviso.');
        $this->assertSame('/inventario?search=ML-10097BWH-1', $send->content['cta_url']);
        $this->assertStringContainsString('ML-10097BWH-1 (x4)', $send->content['body']);
        $this->assertStringContainsString($review->order_number, $send->content['subject']);

        // La campana de Lider Compras guarda ese enlace: el clic navega a Inventario con el producto buscado.
        $this->assertSame(
            '/inventario?search=ML-10097BWH-1',
            Notification::where('user_id', $yirenaId)->where('notification_send_id', $send->id)->value('link_url'),
        );
    }

    // SCRUM-1159 — el aviso al vendedor trae el N° de cotización junto al pedido y un enlace a «Status de Pedidos» (solo lectura, solo lo propio)
    // con el pedido ya buscado. Antes: solo el ID del pedido, sin enlace.
    public function test_aviso_de_sin_stock_al_vendedor_lleva_el_numero_de_cotizacion_y_el_enlace_a_status_de_pedidos(): void
    {
        $sellerId = (int) User::where('email', 'designer@atlantic.test')->value('id');
        $review = $this->makeOrderWithPipelineCard(
            $sellerId,
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 4, 'reference_snapshot' => 'ML-10097BWH-1'],
        );
        $card = $review->pipelineCard;
        Quote::create([
            'master_client_id' => $card->master_client_id, 'sub_client_id' => $card->sub_client_id,
            'sales_project_id' => $card->sales_project_id, 'ruc' => '155-0000-1-2026',
            'name' => 'Cotización 1159', 'description' => 'Cotización 1159', 'owner_id' => $sellerId,
            'status' => 'approved', 'folio' => 'COT-2026-1159', 'generated_at' => now(),
        ]);

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $review->items->first()->id, 'found' => false]],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $send = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE)->where('source_ref', "order:{$review->id}")
            ->get()->first(fn (NotificationSend $n): bool => in_array($sellerId, $n->audience, true));

        $this->assertNotNull($send, 'El dueño de la tarjeta (vendedor) recibe el aviso.');
        $this->assertStringContainsString("{$review->order_number} (cotización COT-2026-1159)", $send->content['subject']);
        $this->assertStringContainsString('COT-2026-1159', $send->content['body']);
        $this->assertSame('/bodega/pedidos/status?search='.rawurlencode($review->order_number), $send->content['cta_url']);
        $this->assertSame(
            $send->content['cta_url'],
            Notification::where('user_id', $sellerId)->where('notification_send_id', $send->id)->value('link_url'),
        );
    }

    // SCRUM-1153 (Pre-QA) — un ítem personalizado (sin referencia) se nombra por su descripción y el aviso no lleva enlace a Inventario.
    public function test_aviso_de_sin_stock_de_un_item_personalizado_o_con_referencia_en_blanco_usa_la_descripcion_y_no_lleva_enlace(): void
    {
        $review = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 5, 'reference_snapshot' => '   ', 'description_snapshot' => 'Producto especial a medida'],   // referencia de solo espacios = sin referencia
        );
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [['order_item_id' => $review->items->first()->id, 'found' => false]],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $yirenaId = (int) User::where('email', 'lidercompras@test.com')->value('id');
        $send = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE)->where('source_ref', "order:{$review->id}")
            ->get()->first(fn (NotificationSend $n): bool => in_array($yirenaId, $n->audience, true));

        $this->assertNotNull($send);
        $this->assertStringContainsString('Producto especial a medida (x5)', $send->content['body']);
        $this->assertStringNotContainsString('Faltan: —', $send->content['body'], 'Antes decía «Faltan: — (x5)».');
        $this->assertArrayNotHasKey('cta_url', $send->content, 'Un producto personalizado no existe en Inventario: no hay a dónde enlazar.');
    }

    // SCRUM-1153 — resultado mixto: el aviso lista SOLO lo que falta (lo encontrado ya salió a otra tarjeta).
    public function test_aviso_de_sin_stock_mixto_lista_solo_lo_que_falta(): void
    {
        $review = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 3, 'reference_snapshot' => 'ITEM-ENCONTRADO'],
        );
        $review->items()->create(['qty_requested' => 2, 'reference_snapshot' => 'ITEM-FALTA']);
        $review->refresh();
        [$found, $missing] = $review->items()->orderBy('id')->get()->all();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", [
                'decisions' => [
                    ['order_item_id' => $found->id, 'found' => true, 'location' => 'D-04'],
                    ['order_item_id' => $missing->id, 'found' => false],
                ],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $send = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE)->where('source_ref', "order:{$review->id}")->firstOrFail();
        $this->assertSame('/inventario?search=ITEM-FALTA', $send->content['cta_url']);
        $this->assertStringContainsString('ITEM-FALTA (x2)', $send->content['body']);
        $this->assertStringNotContainsString('ITEM-ENCONTRADO', $send->content['body']);
    }

    /** RN3 — mixto: se generan 2 tarjetas, ambas vinculadas a la misma familia. */
    public function test_resultado_mixto_genera_2_tarjetas_en_la_misma_familia(): void
    {
        // SCRUM-929 — el ítem "encontrado" se BORRA de la tarjeta original y se recrea en la nueva:
        // el vínculo a la cotización y el precio tienen que sobrevivir a ese split.
        $quoteItemA = self::uniqueTestNumber();
        $quoteItemB = self::uniqueTestNumber();
        $review = $this->makeOrder(
            ['order_type' => Order::ORDER_TYPE_REVISION_INVENTARIO, 'stage' => Order::STAGE_PICKING_PENDIENTE],
            ['qty_requested' => 3, 'reference_snapshot' => 'ITEM-A', 'quote_item_id' => $quoteItemA, 'unit_price' => 120.00],
        );
        $review->items()->create(['qty_requested' => 2, 'reference_snapshot' => 'ITEM-B', 'quote_item_id' => $quoteItemB, 'unit_price' => 45.10]);
        $review->refresh();
        // Sin ORDER BY, Postgres no garantiza el orden de la relación: en la suite completa a veces
        // devolvía B primero y el test confundía qué ítem se movió.
        [$itemA, $itemB] = $review->items()->orderBy('id')->get()->all();

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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

        $moved = $newOrder->items()->first();
        $this->assertSame($quoteItemA, $moved->quote_item_id, 'SCRUM-929: el vínculo sobrevive al split.');
        $this->assertSame(120.0, $moved->unit_price, 'SCRUM-929: el precio sobrevive al split.');
        $stayed = $review->fresh()->items()->first();
        $this->assertSame($quoteItemB, $stayed->quote_item_id);
        $this->assertSame(45.1, $stayed->unit_price);
    }

    /** Pre-QA 2026-07-22 — REQ-318 "PERMISOS: Asistentes y Jefe de Bodega", no Picker ni Repartidor. */
    public function test_resolver_con_rol_no_autorizado_retorna_403(): void
    {
        $review = $this->makeReviewOrder();
        $item = $review->items->first();
        $this->grantBodegaPermissions('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('ayudantegeneralbodega@test.com', 'ayudantegeneralbodega@test.com', 'auxiliar_bodega');

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
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);
        $payload = ['decisions' => [['order_item_id' => $item->id, 'found' => true, 'location' => 'A-01']]];

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", $payload)->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$review->id}/resolve-inventory-review", $payload)->assertStatus(422);
    }
}
