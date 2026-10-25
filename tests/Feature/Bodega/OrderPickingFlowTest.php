<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Events\OrderStageChanged;
use App\Modules\Bodega\Events\PickingCompleted;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — REQ-313/314/315/316/317: Asignado -> Picking pendiente -> En
 * picking -> Packing (o Revisión de Inventario si el picking sale parcial).
 */
class OrderPickingFlowTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    public function test_asignar_picker_mueve_a_picking_pendiente(): void
    {
        $order = $this->makeOrder();
        $this->grantBodegaPermissions('liderbodega@test.com'); // Lider Bodega, lider_bodega
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $pickerId])
            ->assertStatus(200)
            ->assertJsonPath('stage', Order::STAGE_PICKING_PENDIENTE)
            ->assertJsonPath('picker_id', $pickerId);

        $this->makeCurrentTenant();
        $this->assertSame(Order::STAGE_PICKING_PENDIENTE, $order->fresh()->stage);
        $this->assertSame(1, OrderStageHistory::where('order_id', $order->id)->count());
    }

    public function test_asignar_picker_sin_elegir_uno_retorna_422(): void
    {
        $order = $this->makeOrder();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-picker", [])
            ->assertStatus(422);
    }

    public function test_asignar_picker_con_usuario_que_no_es_picker_retorna_422(): void
    {
        $order = $this->makeOrder();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $notAPicker = $this->userIdByEmail('asistentebodega@test.com'); // asistente_bodega, no picker
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $notAPicker])
            ->assertStatus(422);
    }

    /** REQ-313 RN3 — solo Jefe de Bodega/Asistentes pueden asignar picker, no el picker mismo. */
    public function test_asignar_picker_con_rol_no_autorizado_retorna_403(): void
    {
        $order = $this->makeOrder();
        $this->grantBodegaPermissions('ayudantegeneralbodega@test.com');
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('ayudantegeneralbodega@test.com', 'ayudantegeneralbodega@test.com', 'auxiliar_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $pickerId])
            ->assertStatus(403);
    }

    public function test_asignar_picker_en_etapa_incorrecta_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-picker", ['picker_id' => $pickerId])
            ->assertStatus(422);
    }

    public function test_iniciar_picking_sin_picker_asignado_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PICKING_PENDIENTE]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/start-picking")
            ->assertStatus(422);
    }

    public function test_iniciar_picking_mueve_a_en_picking_y_reserva_stock(): void
    {
        $product = $this->makeCatalogProduct();
        $this->setStock($product, 10);
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $order = $this->makeOrder(
            ['stage' => Order::STAGE_PICKING_PENDIENTE, 'picker_id' => $pickerId],
            ['catalog_product_id' => $product->id, 'qty_requested' => 6],
        );

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/start-picking")
            ->assertStatus(200)
            ->assertJsonPath('stage', Order::STAGE_EN_PICKING);

        $this->makeCurrentTenant();
        $item = OrderItem::where('order_id', $order->id)->first();
        $this->assertSame(6, $item->qty_reserved);
    }

    /**
     * Cubre el estado FINAL correcto de 2 llamadas secuenciales (la primera avanza, la segunda ve
     * el estado ya cambiado y rechaza) — NO es una prueba de concurrencia real: ambas llamadas
     * corren en el mismo proceso PHPUnit síncrono, la primera commitea completamente antes de que
     * la segunda arranque, así que nunca ejercita la ventana de carrera real (dos requests que
     * leen el mismo estado ANTES de que cualquiera de las dos commitee). La verificación de
     * concurrencia real (2 procesos/conexiones separados) vive en
     * `Tests\Feature\Bodega\Concurrency\StartPickingConcurrencyTest` — mismo criterio que
     * `AdjustmentRequestControllerTest::test_aprobar_dos_veces_seguidas_solo_aplica_el_ajuste_una_vez()`
     * en Batch A1 (Senior Review 2026-07-22, Blocker 1b).
     */
    /**
     * Pre-QA 2026-07-26 (CRÍTICO) — un actor con rol `transporte` (Courier, cuya función real es
     * la entrega en Packing/Despacho, no el picking) podía operar `start-picking` de CUALQUIER
     * pedido con solo tener `bodega.write` (que la migración de permisos ya le concede también a
     * `transporte`), sin ningún gate de rol. Ver `BodegaRoles::CAN_PICK`.
     */
    public function test_iniciar_picking_con_rol_no_autorizado_retorna_403(): void
    {
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_PICKING_PENDIENTE, 'picker_id' => $pickerId]);
        $this->grantBodegaPermissions('transporte@test.com');
        $token = $this->loginAs('transporte@test.com', 'transporte@test.com', 'transporte');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/start-picking")
            ->assertStatus(403);
    }

    /** Pre-QA 2026-07-26 (CRÍTICO) — el Picker asignado SÍ puede iniciar su propio picking. */
    public function test_iniciar_picking_con_rol_picker_es_permitido(): void
    {
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_PICKING_PENDIENTE, 'picker_id' => $pickerId]);
        $this->grantBodegaPermissions('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('ayudantegeneralbodega@test.com', 'ayudantegeneralbodega@test.com', 'auxiliar_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/start-picking")
            ->assertStatus(200)
            ->assertJsonPath('stage', Order::STAGE_EN_PICKING);
    }

    public function test_iniciar_picking_dos_veces_seguidas_la_segunda_falla(): void
    {
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_PICKING_PENDIENTE, 'picker_id' => $pickerId]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/start-picking")->assertStatus(200);
        $this->withHeaders($headers)->postJson("/api/bodega/orders/{$order->id}/start-picking")->assertStatus(422);
    }

    public function test_hoja_de_picking_ordena_por_ubicacion(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING]);
        $order->items()->create(['qty_requested' => 3, 'location_snapshot' => 'A-01', 'reference_snapshot' => 'A']);
        // Sin `orderBy`, Postgres no garantiza cuál fila devuelve `first()`: a veces era el ítem A-01 y no el de `makeOrder()` (flaky en CI).
        $order->items()->orderBy('id')->first()->update(['location_snapshot' => 'Z-99']);
        $order->items()->create(['qty_requested' => 2, 'location_snapshot' => 'B-02', 'reference_snapshot' => 'B']);

        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}/picking-sheet")
            ->assertStatus(200);

        $locations = collect($response->json('items'))->pluck('location')->all();
        $this->assertSame(['A-01', 'B-02', 'Z-99'], $locations);
    }

    /** REQ-315 — "Ref. fábrica" del ticket original (SCRUM-385), nunca expuesta hasta esta tarea. */
    public function test_hoja_de_picking_expone_factory_reference_del_producto(): void
    {
        $product = $this->makeCatalogProduct(['factory_reference' => 'FAB-999']);
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['catalog_product_id' => $product->id]);

        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}/picking-sheet")
            ->assertStatus(200);

        $this->assertSame('FAB-999', $response->json('items.0.factory_reference'));
    }

    /** REQ-315 — sin producto de Catálogo (ítem custom), `factory_reference` es null, no truena. */
    public function test_hoja_de_picking_factory_reference_null_sin_producto_de_catalogo(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING]);

        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}/picking-sheet")
            ->assertStatus(200);

        $this->assertNull($response->json('items.0.factory_reference'));
    }

    /** REQ-315 — columna "Observación" del ticket original (SCRUM-385), agregada 2026-07-27. */
    public function test_actualizar_picking_sheet_persiste_picking_notes(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 3, 'picking_notes' => 'Caja golpeada, revisar']],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame('Caja golpeada, revisar', $item->fresh()->picking_notes);
    }

    /** REQ-315 — `picking_notes` es opcional, omitirlo no debe romper la actualización de `qty_picked`. */
    public function test_actualizar_picking_sheet_sin_picking_notes_sigue_funcionando(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 3]],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $fresh = $item->fresh();
        $this->assertSame(3, $fresh->qty_picked);
        $this->assertNull($fresh->picking_notes);
    }

    /**
     * REQ-315 — mismo gate de "solo editable en En picking" que ya aplica a `qty_picked`
     * (no se crea un gate nuevo para `picking_notes`).
     */
    public function test_actualizar_picking_sheet_con_picking_notes_fuera_de_en_picking_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 3, 'picking_notes' => 'no debería persistir']],
            ])
            ->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertNull($item->fresh()->picking_notes);
    }

    public function test_hoja_de_picking_no_editable_fuera_de_en_picking(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING]);
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/bodega/orders/{$order->id}/picking-sheet")
            ->assertStatus(200)
            ->assertJsonPath('editable', false);
    }

    /**
     * Pre-QA 2026-07-26 (CRÍTICO) — un actor `transporte` (Courier) podía editar la Hoja de
     * Picking de cualquier pedido, sin gate de rol. Ver `BodegaRoles::CAN_PICK`.
     */
    public function test_actualizar_picking_sheet_con_rol_no_autorizado_retorna_403(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('transporte@test.com');
        $token = $this->loginAs('transporte@test.com', 'transporte@test.com', 'transporte');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 3]],
            ])
            ->assertStatus(403);
    }

    /** Pre-QA 2026-07-26 (CRÍTICO) — el Picker asignado SÍ puede editar su propia Hoja de Picking. */
    public function test_actualizar_picking_sheet_con_rol_picker_es_permitido(): void
    {
        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('ayudantegeneralbodega@test.com', 'ayudantegeneralbodega@test.com', 'auxiliar_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 3]],
            ])
            ->assertStatus(200);
    }

    public function test_actualizar_picking_sheet_no_puede_superar_lo_pedido(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/bodega/orders/{$order->id}/picking-sheet", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 99]],
            ])
            ->assertStatus(422);
    }

    /**
     * Pre-QA 2026-07-26 (CRÍTICO) — un actor `transporte` (Courier) podía completar el picking de
     * cualquier pedido asignado a OTRO picker, sin ningún gate de rol, moviendo silenciosamente
     * reservas de stock y el estado del pedido hacia Packing. Ver `BodegaRoles::CAN_PICK`.
     */
    public function test_completar_picking_con_rol_no_autorizado_retorna_403(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('transporte@test.com');
        $token = $this->loginAs('transporte@test.com', 'transporte@test.com', 'transporte');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 5]],
            ])
            ->assertStatus(403);
    }

    /** Pre-QA 2026-07-26 (CRÍTICO) — el Picker asignado SÍ puede completar su propio picking. */
    public function test_completar_picking_con_rol_picker_es_permitido(): void
    {
        Event::fake([PickingCompleted::class, OrderStageChanged::class, OrderSplit::class]);

        $pickerId = $this->userIdByEmail('ayudantegeneralbodega@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING, 'picker_id' => $pickerId], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('ayudantegeneralbodega@test.com');
        $token = $this->loginAs('ayudantegeneralbodega@test.com', 'ayudantegeneralbodega@test.com', 'auxiliar_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 5]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_PACKING);
    }

    public function test_completar_picking_sin_marcar_todos_los_articulos_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING]);
        $order->items()->create(['qty_requested' => 2, 'reference_snapshot' => 'B']);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $firstItem = $order->items->first();

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
                'items' => [['order_item_id' => $firstItem->id, 'qty_picked' => $firstItem->qty_requested]],
            ])
            ->assertStatus(422);
    }

    public function test_completar_picking_completo_avanza_directo_a_packing(): void
    {
        Event::fake([PickingCompleted::class, OrderStageChanged::class, OrderSplit::class]);

        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], ['qty_requested' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 5]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_PACKING)
            ->assertJsonPath('review', null);

        Event::assertDispatched(PickingCompleted::class);
        Event::assertNotDispatched(OrderSplit::class);
    }

    /** REQ-316/317 — picking parcial crea la tarjeta de Revisión de Inventario, vinculada a la familia. */
    public function test_completar_picking_parcial_crea_tarjeta_de_revision_de_inventario(): void
    {
        Event::fake([PickingCompleted::class, OrderStageChanged::class, OrderSplit::class]);

        $product = $this->makeCatalogProduct();
        // SCRUM-1196 — un producto de catálogo solo se puede alistar de lo que hay en Bodega Central (la de despacho por defecto).
        $this->setStock($product, 10, 'Bodega Central');
        // SCRUM-929 — el ítem padre trae vínculo a la cotización y precio; la tarjeta de revisión
        // debe heredarlos (antes nacía con unit_price NULL y sin ningún vínculo).
        $quoteItemId = self::uniqueTestNumber();
        $order = $this->makeOrder(['stage' => Order::STAGE_EN_PICKING], [
            'catalog_product_id' => $product->id, 'qty_requested' => 10,
            'quote_item_id' => $quoteItemId, 'unit_price' => 199.50,
        ]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/complete-picking", [
                'items' => [['order_item_id' => $item->id, 'qty_picked' => 6]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_PACKING);

        $reviewId = $response->json('review.id');
        $this->assertNotNull($reviewId, 'Se esperaba que se creara la tarjeta de Revisión de Inventario.');

        $this->makeCurrentTenant();
        $review = Order::find($reviewId);
        $this->assertSame(Order::ORDER_TYPE_REVISION_INVENTARIO, $review->order_type);
        $this->assertSame(Order::STAGE_PICKING_PENDIENTE, $review->stage);
        $this->assertSame($order->order_number, $review->order_number, 'HU-335: mismo número de pedido.');
        $this->assertSame($order->id, $review->family_id);
        $this->assertSame(2, $order->fresh()->total_in_family);

        $reviewItem = $review->items->first();
        $this->assertSame(4, $reviewItem->qty_requested, 'REQ-317: 10 pedidos - 6 alistados = 4 en revisión.');
        $this->assertSame($quoteItemId, $reviewItem->quote_item_id, 'SCRUM-929: la revisión hereda el vínculo al QuoteItem.');
        $this->assertSame(199.5, $reviewItem->unit_price, 'SCRUM-929: la revisión hereda el precio (antes quedaba NULL).');
        $this->assertSame($quoteItemId, $item->fresh()->quote_item_id, 'El ítem original conserva su vínculo.');

        Event::assertDispatched(OrderSplit::class);
        Event::assertDispatched(PickingCompleted::class);
    }
}
