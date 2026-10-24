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
use App\Modules\Bodega\Services\DeliveryService;
use App\Modules\Notifications\Models\NotificationSend;
use App\Shared\Multitenancy\Tenant;
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
        $facturar = NotificationSend::where('source', NotificationSend::SOURCE_BODEGA_ORDER_READY_TO_INVOICE)
            ->where('source_ref', "order:{$order->id}")->first();
        $this->assertNotNull($facturar);
        // SCRUM-1167 — el clic de la campana lleva a Facturación.
        $this->assertSame('/admin-contab/facturacion', $facturar->content['cta_url'] ?? null);
    }

    /**
     * SCRUM-914 (hallazgo de Pre-QA, 2026-09-13) — `OrderDocumentService::generateGuiaEntrega()`
     * se llamaba ANTES de `transitionStage(..., STAGE_POR_DESPACHAR, ...)`, así que la guía
     * siempre se generaba con `$order->stage` todavía en 'packing' — impreso tal cual en
     * "Estado de la entrega: {{ $order->stage }}" del PDF, sin importar a qué etapa avanzara el
     * pedido después. Verifica el ORDEN real interceptando la llamada real a
     * `OrderDocumentService::generateGuiaEntrega()` dentro del flujo HTTP completo de
     * `DeliveryService::registerDelivery()` — un test que solo mirara el `stage` final de
     * `$order` (como el test de arriba) no puede distinguir "se generó antes" de "se generó
     * después", ambos terminan en 'por_despachar'.
     */
    public function test_registrar_entrega_genera_la_guia_con_el_pedido_ya_en_por_despachar(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();

        // `OrderDocumentService` es `final` (no se puede mockear parcialmente contra el
        // type-hint del constructor de `DeliveryService`) — en vez de eso, se usa el hook real de
        // Eloquent `OrderDocument::creating()` (se dispara DENTRO de
        // `generateGuiaEntrega()->$order->documents()->create([...])`, misma transacción) para
        // leer el `stage` real del pedido en BD en el momento EXACTO en que se crea el documento.
        $capturedStage = null;
        OrderDocument::creating(function () use ($order, &$capturedStage) {
            $capturedStage = Order::find($order->id)?->stage;
        });

        try {
            $this->grantBodegaPermissions('liderbodega@test.com');
            $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

            $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                    'items' => [['order_item_id' => $item->id, 'qty_delivered' => 5]],
                ])
                ->assertStatus(200);
        } finally {
            OrderDocument::flushEventListeners();
        }

        $this->assertSame(Order::STAGE_POR_DESPACHAR, $capturedStage);
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

        // SCRUM-929 — la tarjeta Faltante hereda vínculo a la cotización y precio del ítem padre.
        $quoteItemId = self::uniqueTestNumber();
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], [
            'qty_requested' => 5, 'qty_picked' => 5, 'quote_item_id' => $quoteItemId, 'unit_price' => 87.25,
        ]);
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
        $this->assertSame($quoteItemId, $faltante->items->first()->quote_item_id);
        $this->assertSame(87.25, $faltante->items->first()->unit_price);
        // Lo entregado queda en el ítem original, con su vínculo intacto: es lo que se factura.
        $this->assertSame(3, $item->fresh()->qty_delivered);
        $this->assertSame($quoteItemId, $item->fresh()->quote_item_id);

        Event::assertDispatched(OrderSplit::class);
    }

    // SCRUM-1148 — el motivo/comentario del artículo pendiente queda persistido y se expone en el detalle
    // de AMBAS tarjetas (la original ya despachada parcialmente y la Faltante), con el artículo correcto.
    public function test_comentario_del_articulo_pendiente_se_conserva_y_se_expone_tras_el_despacho_parcial(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);

        $this->withHeaders($headers)
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", [
                'items' => [['order_item_id' => $item->id, 'qty_delivered' => 3, 'motivo' => 'otra', 'motivo_detalle' => 'Llega el martes con el proveedor']],
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $faltanteId = Order::where('order_type', Order::ORDER_TYPE_FALTANTE)->where('order_number', $order->order_number)->value('id');
        $this->assertNotNull($faltanteId);

        foreach ([$order->id, $faltanteId] as $orderId) {
            $detail = $this->withHeaders($headers)->getJson("/api/bodega/orders/{$orderId}")->assertStatus(200);
            $this->assertSame('otra:Llega el martes con el proveedor', $detail->json('items.0.shortage_reason'));
            $this->assertSame('REF-TEST', $detail->json('items.0.reference'), 'El comentario corresponde al artículo registrado.');
        }
    }

    // SCRUM-1148 (Pre-QA) — el texto libre de "Otra" tiene tope (shortage_reason es varchar(255) con prefijo "otra:"):
    // 250 caracteres pasan, 251 devuelven 422 con el campo señalado (antes 500).
    public function test_motivo_detalle_largo_se_valida_en_vez_de_devolver_500(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_PACKING], ['qty_requested' => 5, 'qty_picked' => 5]);
        $item = $order->items->first();
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read', 'bodega.write']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');
        $headers = $this->authHeaders($token);
        $payload = fn (int $len) => ['items' => [['order_item_id' => $item->id, 'qty_delivered' => 3, 'motivo' => 'otra', 'motivo_detalle' => str_repeat('a', $len)]]];

        $this->withHeaders($headers)
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", $payload(251))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.motivo_detalle']);

        $this->withHeaders($headers)
            ->postJson("/api/bodega/orders/{$order->id}/register-delivery", $payload(250))
            ->assertStatus(200);
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

    // SCRUM-1142 — "Personal de Bodega" (retiro directo del cliente) se asigna como cualquier repartidor,
    // se guarda y deja pasar el gate de despacho ("Falta asignar el repartidor").
    public function test_asignar_personal_de_bodega_como_repartidor_se_guarda_y_permite_despachar(): void
    {
        $order = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR]);
        $personalId = $this->userIdByEmail('personalbodega@test.com');
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/assign-courier", ['courier_id' => $personalId])
            ->assertStatus(200)
            ->assertJsonPath('courier_id', $personalId);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame($personalId, Order::findOrFail($order->id)->courier_id);
        $this->assertSame([], app(DeliveryService::class)->blockingReasonsForDispatch(Order::findOrFail($order->id)));
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

    /** SCRUM-860 — REQ-328 (bloqueo de despacho sin factura) se elimina a propósito: Admin ya
     *  recibe la notificación de facturación desde registerDelivery(), no hace falta bloquear acá. */
    public function test_despachar_sin_factura_ya_no_bloquea(): void
    {
        $courierId = $this->userIdByEmail('transporte@test.com');
        $order = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => false]);
        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$order->id}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_DESPACHADO);
    }

    /** REQ-319 RN3 — despacho conjunto SOLO si el hermano también tiene repartidor propio.
     *  SCRUM-860 — un hermano sin factura ya se despacha igual (REQ-328 eliminado). */
    public function test_despachar_arrastra_hermano_listo_incluso_sin_factura(): void
    {
        Event::fake([OrderDispatched::class]);

        $courierId = $this->userIdByEmail('transporte@test.com');
        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'courier_id' => $courierId, 'invoice_ready' => true]);
        $readySiblingSinFactura = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id, 'courier_id' => $courierId, 'invoice_ready' => false]);
        $siblingSinRepartidor = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id, 'courier_id' => null, 'invoice_ready' => true]);

        $this->grantBodegaPermissions('liderbodega@test.com');
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/bodega/orders/{$root->id}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('order.stage', Order::STAGE_DESPACHADO);

        $this->makeCurrentTenant();
        $this->assertSame(Order::STAGE_DESPACHADO, $readySiblingSinFactura->fresh()->stage, 'El hermano sin factura se despacha igual (SCRUM-860).');
        $this->assertSame(Order::STAGE_POR_DESPACHAR, $siblingSinRepartidor->fresh()->stage, 'El que no tiene repartidor se queda esperando.');

        $dispatchedIds = collect($response->json('dispatched_siblings'))->pluck('id')->all();
        $this->assertContains($readySiblingSinFactura->id, $dispatchedIds);
        $this->assertNotContains($siblingSinRepartidor->id, $dispatchedIds);

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
