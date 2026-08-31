<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Bodega\Events\CourierAssigned;
use App\Modules\Bodega\Events\DeliveryRegistered;
use App\Modules\Bodega\Events\OrderDelivered;
use App\Modules\Bodega\Events\OrderDispatched;
use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Notifications\Models\NotificationSend;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-329 Oleada A / Batch A2 — REQ-323 a 330: Packing -> Por despachar -> Despachado ->
 * Entregado (registrar entrega/guía, repartidor, bloqueo de despacho, guía firmada).
 */
class DeliveryFlowTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Storage::fake('s3-private');
    }

    public function test_registrar_entrega_completa_avanza_a_por_despachar_y_genera_guia(): void
    {
        // Pre-QA 2026-07-22: qty_picked explícito (=qty_requested) — un pedido real en Packing
        // siempre pasó por completePicking(), que deja qty_picked resuelto para TODO ítem (ver
        // fix del cap en DeliveryService::registerDelivery()).
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 5]],
            ])
            ->assertStatus(200)
            ->assertJsonPath('stage', Order::STAGE_POR_DESPACHAR);

        $this->makeCurrentTenant();
        $this->assertSame(5, $item->fresh()->qty_delivered);
        $this->assertSame(
            OrderDocument::TIPO_GUIA_ENTREGA,
            OrderDocument::where('order_id', $order->id)->value('tipo'),
        );
        $this->assertNotNull(
            NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_READY_TO_INVOICE)
                ->where('source_ref', "order:{$order->id}")->first(),
        );
    }

    /** REQ-324 RN2 — motivo obligatorio por artículo si la entrega quedó parcial. */
    public function test_registrar_entrega_parcial_sin_motivo_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 3]],
            ])
            ->assertStatus(422);
    }

    /** REQ-325 — entrega parcial crea la tarjeta "Faltante" en Asignado, vinculada a la familia. */
    public function test_registrar_entrega_parcial_crea_tarjeta_faltante(): void
    {
        Event::fake([OrderSplit::class]);

        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 3, 'motivo' => 'sin_stock']],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $faltante = Order::where('order_type', Order::ORDER_TYPE_FALTANTE)->where('order_number', $order->order_number)->first();
        $this->assertNotNull($faltante);
        $this->assertSame(Order::STAGE_ASIGNADO, $faltante->stage);
        $this->assertSame(2, $faltante->items->first()->qty_requested);
        $this->assertSame($order->id, $faltante->family_id);

        Event::assertDispatched(OrderSplit::class);
    }

    /**
     * Pre-QA 2026-07-22 (CRÍTICO, corregido en vivo) — RN3 de REQ-323: un ítem que llegó a Packing
     * con `qty_picked = 0` (totalmente no encontrado durante el picking, ya derivado a su propia
     * tarjeta de Revisión de Inventario/Faltante por separado) no puede "entregarse" por encima de
     * ese 0 en la tarjeta original — antes de este fix, el tope caía a `qty_requested` completo
     * (bug: `qty_picked > 0 ? qty_picked : qty_requested` trataba 0 como "no picked yet" en vez de
     * "confirmado en cero"), permitiendo una entrega fantasma duplicada de la misma mercancía física
     * que la tarjeta separada ya gestiona.
     */
    public function test_registrar_entrega_de_item_con_qty_picked_cero_queda_bloqueado_en_cero(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 0]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        // Intentar entregar más de 0 (el tope real) debe rechazarse — antes del fix esto devolvía 200.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 5]],
            ])
            ->assertStatus(422);

        // Entregar exactamente 0 (el tope real) sí debe aceptarse — es un "todo faltante" legítimo.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 0, 'motivo' => 'sin_stock']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('stage', Order::STAGE_POR_DESPACHAR);
    }

    /** Pre-QA 2026-07-22 — REQ-323/324/325 "PERMISOS: Asistentes y Jefe de Bodega", no Picker ni Repartidor. */
    public function test_registrar_entrega_con_rol_no_autorizado_retorna_403(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('transporte@test.com');
        $token = $this->loginAs('transporte@test.com', 'transporte@test.com', 'transporte');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 5]],
            ])
            ->assertStatus(403);
    }

    public function test_asignar_repartidor_solo_jefe_de_bodega(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR]);
        $courierId = $this->userIdByEmail('transporte@test.com');
        $this->grantBodegaPermissions('asistentebodega@test.com'); // Osvaldo, asistente_bodega
        $token = $this->loginAs('asistentebodega@test.com', 'asistentebodega@test.com', 'asistente_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-courier", ['courier_id' => $courierId])
            ->assertStatus(403);
    }

    public function test_asignar_repartidor_propaga_a_hermano_de_familia_en_por_despachar(): void
    {
        Event::fake([CourierAssigned::class]);

        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR]);
        $sibling = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id]);
        $courierId = $this->userIdByEmail('transporte@test.com');
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$root->id}/assign-courier", ['courier_id' => $courierId])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame($courierId, $sibling->fresh()->courier_id, 'REQ-326 RN2: propagación automática a la familia.');
        Event::assertDispatchedTimes(CourierAssigned::class, 2);
    }

    /**
     * Lote 4 (SCRUM-396, 2026-08-17) — "Cambiar repartidor" (reasignar un pedido que YA tiene
     * repartidor) debe sincronizar también a los hermanos que YA tenían un repartidor propio, no
     * solo a los que estaban sin asignar (comportamiento previo, cubierto por el test de arriba).
     */
    public function test_cambiar_repartidor_ya_asignado_sincroniza_a_hermano_que_tambien_ya_tenia_uno(): void
    {
        Event::fake([CourierAssigned::class]);

        $originalCourierId = $this->userIdByEmail('transporte@test.com');
        $newCourier = $this->makeCourier('Segundo Repartidor');

        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $originalCourierId]);
        $sibling = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id, 'courier_id' => $originalCourierId]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$root->id}/assign-courier", ['courier_id' => $newCourier->id])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertSame($newCourier->id, $root->fresh()->courier_id);
        $this->assertSame(
            $newCourier->id,
            $sibling->fresh()->courier_id,
            'Lote 4 (SCRUM-396): cambiar repartidor debe re-sincronizar a un hermano que ya tenía uno, no solo a los vacíos.',
        );
        Event::assertDispatchedTimes(CourierAssigned::class, 2);
    }

    private function makeCourier(string $firstName): User
    {
        $role = Role::where('key', 'transporte')->firstOrFail();
        $level = SecurityLevel::where('level', 1)->firstOrFail();

        return User::create([
            'first_name' => $firstName, 'last_name' => 'Test',
            'email' => strtolower(str_replace(' ', '.', $firstName)).'@atlantic.test',
            'password_hash' => 'Password123!', 'is_active' => true,
            'security_level_id' => $level->id, 'role_id' => $role->id,
        ]);
    }

    public function test_despachar_sin_repartidor_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'invoice_ready' => true]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Falta asignar el repartidor.']);
    }

    public function test_despachar_sin_factura_retorna_422(): void
    {
        $courierId = $this->userIdByEmail('transporte@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => false]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Falta la factura de Administración.']);
    }

    /** REQ-319 RN3/REQ-328 RN3 — despacho conjunto SOLO si el hermano también tiene repartidor+factura propios. */
    public function test_despachar_arrastra_hermano_listo_pero_no_al_que_le_falta_factura(): void
    {
        Event::fake([OrderDispatched::class]);

        $courierId = $this->userIdByEmail('transporte@test.com');
        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => true]);
        $readySibling = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id, 'courier_id' => $courierId, 'invoice_ready' => true]);
        $notReadySibling = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id, 'courier_id' => $courierId, 'invoice_ready' => false]);

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$root->id}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_DESPACHADO);

        $this->makeCurrentTenant();
        $this->assertSame(Order::STAGE_DESPACHADO, $readySibling->fresh()->stage, 'El hermano listo se despacha junto.');
        $this->assertSame(Order::STAGE_POR_DESPACHAR, $notReadySibling->fresh()->stage, 'El que no tiene factura se queda esperando.');

        $dispatchedIds = collect($response->json('dispatched_siblings'))->pluck('id')->all();
        $this->assertContains($readySibling->id, $dispatchedIds);
        $this->assertNotContains($notReadySibling->id, $dispatchedIds);

        Event::assertDispatchedTimes(OrderDispatched::class, 2);
    }

    public function test_subir_guia_firmada_avanza_a_entregado(): void
    {
        Event::fake([DeliveryRegistered::class, OrderDelivered::class]);

        $courierId = $this->userIdByEmail('transporte@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_DESPACHADO, 'courier_id' => $courierId]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-signed-guide", [
                'delivered_by_courier_id' => $courierId,
                'received_by_name' => 'Ana Torres',
                'file' => UploadedFile::fake()->create('guia.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(200)
            ->assertJsonPath('stage', Order::STAGE_ENTREGADO);

        Event::assertDispatched(DeliveryRegistered::class);
        Event::assertDispatched(OrderDelivered::class);

        $this->makeCurrentTenant();
        $this->assertSame(
            'Ana Torres',
            OrderDocument::where('order_id', $order->id)->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)->latest('id')->value('received_by_name'),
            'Pre-QA 2026-07-23 (CRÍTICO) — quien RECIBIÓ el pedido debe quedar registrado en el documento, distinto del repartidor.',
        );
    }

    /** Pre-QA 2026-07-23 (CRÍTICO) — sin `received_by_name` no hay forma de saber quién recibió realmente. */
    public function test_subir_guia_firmada_sin_nombre_de_receptor_retorna_422(): void
    {
        $courierId = $this->userIdByEmail('transporte@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_DESPACHADO, 'courier_id' => $courierId]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-signed-guide", [
                'delivered_by_courier_id' => $courierId,
                'file' => UploadedFile::fake()->create('guia.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_subir_guia_firmada_en_etapa_incorrecta_retorna_422(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/register-signed-guide", [
                'delivered_by_courier_id' => $this->userIdByEmail('transporte@test.com'),
                'received_by_name' => 'Ana Torres',
                'file' => UploadedFile::fake()->create('guia.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }
}
