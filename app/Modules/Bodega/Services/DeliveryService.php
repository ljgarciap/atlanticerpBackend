<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Events\CourierAssigned;
use App\Modules\Bodega\Events\DeliveryRegistered;
use App\Modules\Bodega\Events\OrderDelivered;
use App\Modules\Bodega\Events\OrderDispatched;
use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Services\Concerns\ChangesOrderStage;
use App\Modules\Bodega\Support\BodegaRoles;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-329 Oleada A / Batch A2 — Packing -> Por despachar -> Despachado -> Entregado: registrar
 * entrega y generar guía (REQ-323/324/325), asignar repartidor (REQ-326), bloqueo de avance sin
 * repartidor/factura (REQ-328), y subir la guía firmada que cierra el pedido (REQ-329).
 *
 * `dispatch()` cierra el Kardex: llama a `KardexService::registrar()` (Sprint 2, RN2 de REQ-402 —
 * "Salida desde Pedidos Despachado") por cada `order_item` con `warehouse_id` real, poblado al
 * completar el picking (ver `PickingService::completePicking()` + `StockReservationService`).
 *
 * Senior Review 2026-07-22 (Blocker 1, el más grave de los 4 en este service — dos `dispatch()`
 * casi simultáneos sobre el mismo pedido sin ningún lock producían un doble decremento de Kardex,
 * "double-spend" real de inventario) — las 4 transiciones (`registerDelivery`, `assignCourier`,
 * `dispatch`/`dispatchOne`, `registerSignedGuide`) re-obtienen el `Order` con `lockForUpdate()`
 * DENTRO de la transacción (`ChangesOrderStage::lockOrder()`) y vuelven a validar el estado contra
 * esa copia bloqueada — mismo patrón que ya corrigió el CRÍTICO real de
 * `AdjustmentRequestController::approve()`/`reject()`. `dispatch()` además bloquea a cada hermano
 * de familia individualmente antes de despacharlo junto (no solo al pedido principal).
 *
 * Re-revisión 2026-07-22 (hallazgo 🟡 no bloqueante) — el orden de adquisición de locks entre
 * `Order`/`OrderItem` propios y `ProductWarehouseStock`/`OrderItem` de otros pedidos competidores
 * no es el mismo en todos los services de Bodega, lo que puede producir un deadlock real (no
 * corrupción) detectado por Postgres cuando 2 pedidos DISTINTOS compiten por el mismo producto de
 * forma concurrente. Las 4 transiciones pasan `$attempts = 3` — Laravel reintenta automáticamente
 * el closure completo si detecta un deadlock (`Connection::causedByDeadlock()`), sin necesidad de
 * unificar el orden de locks entre services.
 */
final class DeliveryService
{
    use ChangesOrderStage;

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly FamilyService $family,
        private readonly OrderDocumentService $documents,
        private readonly NotificationService $notifications,
        private readonly KardexService $kardex,
    ) {}

    /**
     * REQ-323/324/325 — Packing -> Por despachar. RN1: "A entregar" no puede superar lo pedido
     * (el tope real es `qty_picked` si el pedido llegó reducido de un picking parcial — REQ-317
     * RN1 — o `qty_requested` en el caso normal). RN2: motivo obligatorio por artículo si quedó
     * parcial (REQ-324). Si algo queda pendiente, crea la tarjeta "Faltante" (REQ-325 RN1).
     *
     * Pre-QA 2026-07-22 (criterio confirmado contra Jira, REQ-323/324/325 "PERMISOS: Asistentes y
     * Jefe de Bodega") — registrar la entrega está acotado al mismo `CAN_OPERATE` que
     * `assignPicker`, a diferencia de otros pasos abiertos a "todos los perfiles de Bodega" (ej.
     * completePicking). El Picker y el Repartidor NO deberían poder registrar la entrega aunque
     * tengan `bodega.write`.
     *
     * @param list<array{order_item_id: int, qty_delivered: int, motivo?: string|null, motivo_detalle?: string|null}> $items
     */
    public function registerDelivery(Order $order, array $items, ?string $observacionGeneral, User $actor): Order
    {
        if (! in_array($actor->role?->key, BodegaRoles::CAN_OPERATE, true)) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega o los Asistentes de Bodega pueden registrar la entrega.',
                status: 403,
            );
        }

        $submittedIds = collect($items)->pluck('order_item_id')->map(fn ($id) => (int) $id)->all();

        return DB::transaction(function () use ($order, $items, $submittedIds, $observacionGeneral, $actor): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_PACKING) {
                throw new OrderTransitionException('El pedido no está en la etapa Packing.');
            }

            $locked->loadMissing('items');
            $allIds = $locked->items->pluck('id')->all();
            if (array_diff($allIds, $submittedIds) !== []) {
                throw new OrderTransitionException(
                    'Faltan artículos por registrar en la entrega.',
                    ['items' => ['Faltan artículos por registrar en la entrega.']],
                );
            }

            $shortfalls = [];

            foreach ($items as $row) {
                /** @var OrderItem $item */
                $item = $locked->items()->lockForUpdate()->findOrFail($row['order_item_id']);
                // Pre-QA 2026-07-22 (CRÍTICO) — el tope de entrega SIEMPRE es qty_picked, nunca un
                // fallback a qty_requested. Por RN1 de completePicking() (todos los order_items del
                // pedido deben venir en el payload), un pedido que llegó a Packing YA tiene qty_picked
                // resuelto para TODOS sus ítems, incluido el valor 0 (item totalmente no encontrado,
                // que dispara su propia tarjeta de Revisión de Inventario/Faltante por separado). El
                // fallback viejo (`qty_picked > 0 ? qty_picked : qty_requested`) trataba qty_picked=0
                // como "todavía no se tocó" y permitía "entregar" hasta qty_requested completo de un
                // ítem que en realidad nunca se recogió — doble entrega de la misma mercancía física
                // (una vez acá, fantasma, y otra vez de verdad vía la tarjeta separada que ya la
                // gestiona). Confirmado en vivo: POST register-delivery con qty_delivered=qty_requested
                // sobre un ítem con qty_picked=0 devolvía 200 antes de este fix.
                $cap = $item->qty_picked;

                if ($row['qty_delivered'] < 0 || $row['qty_delivered'] > $cap) {
                    throw new OrderTransitionException(
                        'La cantidad a entregar no puede superar lo pedido/alistado.',
                        ['items' => ["order_item_id {$item->id}: cantidad a entregar inválida."]],
                    );
                }

                $motivo    = $row['motivo'] ?? null;
                $isPartial = $row['qty_delivered'] < $cap;
                if ($isPartial && trim((string) $motivo) === '') {
                    throw new OrderTransitionException(
                        'Falta el motivo de entrega parcial para al menos un artículo.',
                        ['items' => ["order_item_id {$item->id}: falta el motivo de entrega parcial."]],
                    );
                }

                $item->update(['qty_delivered' => $row['qty_delivered']]);

                if ($isPartial) {
                    $item->setShortageReason((string) $motivo, $row['motivo_detalle'] ?? null);
                    $shortfalls[] = ['item' => $item, 'remaining' => $cap - $row['qty_delivered']];
                }
            }

            if ($observacionGeneral !== null && trim($observacionGeneral) !== '') {
                // No hay columna dedicada para la observación general del pedido — se persiste
                // como una fila de `order_stage_history` no es apropiado (es auditoría de etapa,
                // no de contenido); queda documentada en el log de aplicación hasta que el
                // Arquitecto confirme dónde debe vivir (ver reporte del batch).
                Log::info('bodega.order.delivery-general-observation', [
                    'order_id' => $locked->id, 'observacion' => $observacionGeneral,
                ]);
            }

            $this->documents->generateGuiaEntrega($locked, $actor);
            $this->notifyAdminToInvoice($locked, $actor);
            $this->transitionStage($locked, Order::STAGE_POR_DESPACHAR, $actor->id);

            if ($shortfalls !== []) {
                $this->createFaltanteCard($locked, $shortfalls, $actor);
            }

            /** @var list<int> $productIds */
            $productIds = $locked->items()->whereNotNull('catalog_product_id')->pluck('catalog_product_id')->unique()->values()->map(fn ($id) => (int) $id)->all();
            $this->reservations->reallocateForProducts($productIds);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /** @param list<array{item: OrderItem, remaining: int}> $shortfalls */
    private function createFaltanteCard(Order $order, array $shortfalls, User $actor): Order
    {
        $faltante = Order::create([
            'order_number'            => $order->order_number,
            'pipeline_card_id'        => $order->pipeline_card_id,
            'order_type'              => Order::ORDER_TYPE_FALTANTE,
            'stage'                   => Order::STAGE_ASIGNADO,
            'family_id'               => $order->familyRootId(),
            'assistant_id'            => $order->assistant_id,
            'committed_delivery_date' => $order->committed_delivery_date,
            'customer_name'           => $order->customer_name,
            'customer_contact'        => $order->customer_contact,
            'delivery_address'        => $order->delivery_address,
        ]);

        foreach ($shortfalls as $s) {
            /** @var OrderItem $item */
            $item = $s['item'];
            $faltante->items()->create([
                'catalog_product_id'   => $item->catalog_product_id,
                'reference_snapshot'   => $item->reference_snapshot,
                'description_snapshot' => $item->description_snapshot,
                'qty_requested'        => $s['remaining'],
                'shortage_reason'      => $item->shortage_reason,
            ]);
        }

        OrderStageHistory::create([
            'order_id' => $faltante->id, 'from_stage' => null, 'to_stage' => Order::STAGE_ASIGNADO,
            'changed_by' => null, 'created_at' => now(),
        ]);

        $this->family->attach($order, $faltante);

        event(new OrderSplit(
            parentOrderId: $order->id,
            orderNumber: $order->order_number,
            childOrderIds: [$faltante->id],
            tenant: $this->requiredTenantSlug(),
        ));

        return $faltante->fresh('items') ?? $faltante;
    }

    /** REQ-326 RN1/RN2 — exclusivo del Jefe de Bodega, propaga a hermanos de familia en Por despachar sin repartidor propio. */
    /**
     * REQ-326, alcance ampliado en Lote 4 (SCRUM-396, 2026-08-17) — "Cambiar repartidor" reusa
     * este mismo método para reasignar un pedido que YA tiene repartidor (antes solo se llamaba
     * una vez, mientras `courier_id` era null). RN2 (propagación a la familia) se ajusta acorde:
     * antes solo llenaba huérfanos sin repartidor (`courier_id IS NULL`) en el primer assign;
     * ahora sincroniza SIEMPRE a todos los hermanos en Por despachar con el nuevo repartidor,
     * tengan o no uno ya asignado — la lectura literal de RN2 ("propaga... a los demás miembros
     * de la familia que estén ahí") no distingue asignación inicial de cambio, y una familia con
     * repartidores desincronizados contradice el propósito del RN.
     */
    public function assignCourier(Order $order, int $courierId, User $actor): Order
    {
        // Rol del actor y validez del repartidor no dependen del estado del Order — se resuelven
        // antes de pagar el costo de abrir transacción (Blocker 1 solo exige lock+recheck sobre
        // lo que sí puede cambiar por concurrencia: el `stage`).
        if ($actor->role?->key !== BodegaRoles::LIDER_BODEGA) {
            throw new OrderTransitionException('Solo el Jefe de Bodega puede asignar el repartidor.', status: 403);
        }

        $courier = User::find($courierId);
        if ($courier === null || $courier->role?->key !== BodegaRoles::COURIER || ! $courier->is_active) {
            throw new OrderTransitionException('El repartidor seleccionado no es válido.', ['courier_id' => ['El repartidor seleccionado no es válido.']]);
        }

        return DB::transaction(function () use ($order, $courierId): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_POR_DESPACHAR) {
                throw new OrderTransitionException('El pedido no está en la etapa Por despachar.');
            }

            $locked->update(['courier_id' => $courierId]);
            event(new CourierAssigned($locked->id, $locked->order_number, $courierId, $this->requiredTenantSlug()));

            foreach ($this->family->members($locked) as $sibling) {
                if ($sibling->id === $locked->id) {
                    continue;
                }

                // Cada hermano se bloquea/revalida individualmente antes de tocarlo — pudo haber
                // cambiado de etapa entre el fetch de family->members() (sin lock) y este punto.
                // Sincroniza SIEMPRE (ya tenga o no un repartidor previo, ver docblock del método)
                // — solo se salta si ya no está en Por despachar o ya tiene este MISMO repartidor
                // (evita un evento CourierAssigned redundante).
                $lockedSibling = Order::where('id', $sibling->id)->lockForUpdate()->first();
                if ($lockedSibling === null || $lockedSibling->stage !== Order::STAGE_POR_DESPACHAR || $lockedSibling->courier_id === $courierId) {
                    continue;
                }

                $lockedSibling->update(['courier_id' => $courierId]);
                event(new CourierAssigned($lockedSibling->id, $lockedSibling->order_number, $courierId, $this->requiredTenantSlug()));
            }

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /**
     * REQ-328 — Por despachar -> Despachado, bloqueado sin repartidor ni factura. RN3/REQ-319 RN3:
     * despacho conjunto de familia — cada hermano en Por despachar se despacha junto SOLO si él
     * mismo tiene su propio repartidor y factura listos; si no, queda esperando con su propia
     * razón (expuesta por el serializer del board, no una columna nueva).
     *
     * Blocker 1 (Senior Review 2026-07-22) — el caso más grave del batch: dos requests de
     * `dispatch()` sobre el mismo pedido, casi simultáneas, podían producir un doble decremento
     * de Kardex (la segunda transacción, sin lock de `Order`, no volvía a mirar el `stage` después
     * de esperar el lock incidental de `product_warehouse_stock`). Ahora TODO — el chequeo de
     * `stage`, el gate de bloqueo, y el despacho de cada hermano de familia — vive dentro de la
     * transacción, contra copias de `Order` bloqueadas con `lockForUpdate()`.
     *
     * @return array{order: Order, dispatchedSiblings: list<Order>}
     */
    public function dispatch(Order $order, User $actor): array
    {
        return DB::transaction(function () use ($order, $actor): array {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_POR_DESPACHAR) {
                throw new OrderTransitionException('El pedido no está en la etapa Por despachar.');
            }

            $blocking = $this->blockingReasonsForDispatch($locked);
            if ($blocking !== []) {
                throw new OrderTransitionException(implode(' ', $blocking), ['order' => $blocking]);
            }

            $this->dispatchOne($locked, $actor);

            /** @var list<Order> $dispatchedSiblings */
            $dispatchedSiblings = [];
            foreach ($this->family->members($locked) as $sibling) {
                if ($sibling->id === $locked->id || $sibling->stage !== Order::STAGE_POR_DESPACHAR) {
                    continue;
                }

                // Mismo criterio que assignCourier(): re-lockear y re-validar cada hermano antes
                // de despacharlo — nunca confiar en la copia sin lock de family->members().
                $lockedSibling = Order::where('id', $sibling->id)->lockForUpdate()->first();
                if ($lockedSibling === null || $lockedSibling->stage !== Order::STAGE_POR_DESPACHAR) {
                    continue;
                }
                if ($this->blockingReasonsForDispatch($lockedSibling) === []) {
                    $this->dispatchOne($lockedSibling, $actor);
                    $dispatchedSiblings[] = $lockedSibling->fresh() ?? $lockedSibling;
                }
            }

            return ['order' => $locked->fresh() ?? $locked, 'dispatchedSiblings' => $dispatchedSiblings];
        }, 3);
    }

    /** @return list<string> */
    public function blockingReasonsForDispatch(Order $order): array
    {
        $reasons = [];
        if ($order->courier_id === null) {
            $reasons[] = 'Falta asignar el repartidor.';
        }
        if (! $order->invoice_ready) {
            $reasons[] = 'Falta la factura de Administración.';
        }

        return $reasons;
    }

    private function dispatchOne(Order $order, User $actor): void
    {
        $this->registerKardexExits($order, $actor);

        $this->transitionStage($order, Order::STAGE_DESPACHADO, $actor->id);
        event(new OrderDispatched($order->id, $order->order_number, $order->courier_id, $this->requiredTenantSlug()));

        /** @var list<int> $productIds */
        $productIds = $order->items()->whereNotNull('catalog_product_id')->pluck('catalog_product_id')->unique()->values()->map(fn ($id) => (int) $id)->all();
        $this->reservations->reallocateForProducts($productIds);
    }

    /**
     * REQ-402 RN2 ("Salida desde Pedidos Despachado") — un movimiento de Kardex por artículo
     * realmente entregado. Si a un `order_item` le falta `warehouse_id` (no debería pasar si el
     * picking lo pobló bien — ver `PickingService::completePicking()`) se loggea y se sigue: un
     * dato de Kardex faltante no puede bloquear el despacho real de la mercancía, mismo criterio
     * de "nunca bloquear el flujo de negocio principal por un dato faltante" que ya usa
     * `OrderService::createFromApprovedQuote()` (Batch A1) para el mapeo de asistente. Llamado
     * SIEMPRE con un `$order` ya bloqueado (`lockForUpdate()`) por `dispatch()` — el lock sobre
     * `product_warehouse_stock` que toma `KardexService::registrar()` internamente ahora solo
     * serializa acceso concurrente a bodegas distintas, ya no es la única defensa contra un doble
     * despacho del mismo pedido.
     */
    private function registerKardexExits(Order $order, User $actor): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->catalog_product_id === null || $item->qty_delivered <= 0) {
                continue; // ítem personalizado (sin producto de Catálogo) o nada entregado — nada que mover.
            }

            if ($item->warehouse_id === null) {
                Log::error('bodega.order.dispatch-missing-warehouse-for-kardex', [
                    'order_id'      => $order->id,
                    'order_number'  => $order->order_number,
                    'order_item_id' => $item->id,
                ]);
                continue;
            }

            $this->kardex->registrar(
                catalogProductId: $item->catalog_product_id,
                warehouseId: $item->warehouse_id,
                tipo: InventoryMovement::TIPO_SALIDA,
                cantidad: -$item->qty_delivered,
                referenciaTexto: "Pedido {$order->order_number} despachado",
                referenciaEntidadTipo: 'PEDIDO',
                referenciaEntidadId: $order->id,
                realizadoPor: $actor->id,
            );
        }
    }

    /**
     * REQ-329 — Despachado -> Entregado, automático al confirmar la guía firmada.
     *
     * Pre-QA 2026-07-23 (CRÍTICO, decisión de Luis) — `receivedByName` es el nombre de quien
     * RECIBIÓ el pedido (firma real en la dirección de entrega), distinto de `deliveredByCourierId`
     * (quién lo entregó). REQ-330 pide mostrar el primero en la Guía de Entrega ya firmada; antes
     * de este fix ese dato no existía en ningún lado y la UI mostraba el repartidor en su lugar.
     */
    public function registerSignedGuide(Order $order, UploadedFile $file, int $deliveredByCourierId, string $receivedByName, User $actor): Order
    {
        return DB::transaction(function () use ($order, $file, $deliveredByCourierId, $receivedByName, $actor): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_DESPACHADO) {
                throw new OrderTransitionException('El pedido no está en la etapa Despachado.');
            }

            $storageKey = $this->documents->uploadSignedGuide($locked, $file);
            OrderDocument::create([
                'order_id' => $locked->id, 'tipo' => OrderDocument::TIPO_GUIA_ENTREGA,
                'storage_key' => $storageKey, 'received_by_name' => $receivedByName,
                'uploaded_by' => $actor->id, 'created_at' => now(),
            ]);
            $locked->update(['courier_id' => $deliveredByCourierId]);

            $this->transitionStage($locked, Order::STAGE_ENTREGADO, $actor->id);

            event(new DeliveryRegistered($locked->id, $locked->order_number, $actor->id, $this->requiredTenantSlug()));
            event(new OrderDelivered($locked->id, $locked->order_number, $this->requiredTenantSlug()));

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /** REQ-325 — notifica a Administración & Contabilidad para que facture el pedido. */
    private function notifyAdminToInvoice(Order $order, User $actor): void
    {
        $roleId = Role::where('key', 'lider_admin_contab')->value('id');
        /** @var list<int> $audience */
        $audience = $roleId !== null
            ? User::where('role_id', $roleId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];

        if ($audience === []) {
            return;
        }

        $this->notifications->send(
            audience: $audience,
            content: [
                'subject' => "Pedido {$order->order_number} listo para facturar",
                'body'    => "El pedido {$order->order_number} guardó su entrega en Bodega y está listo para "
                    .'que Administración & Contabilidad genere la factura.',
            ],
            channels: [NotificationSend::CHANNEL_IN_APP, NotificationSend::CHANNEL_EMAIL],
            source: NotificationSend::SOURCE_BODEGA_ORDER_READY_TO_INVOICE,
            sourceRef: "order:{$order->id}",
            createdBy: $actor->id,
        );
    }
}
