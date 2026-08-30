<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Events\PickingCompleted;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Services\Concerns\ChangesOrderStage;
use App\Modules\Bodega\Support\BodegaRoles;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A2 — flujo de picking: asignar picker (REQ-313), iniciar picking
 * (REQ-314), editar la Hoja de Picking (REQ-315), y completarlo (REQ-316), incl. el disparo
 * automático de la tarjeta de Revisión de Inventario en picking parcial (REQ-317).
 *
 * Senior Review 2026-07-22 (Blocker 1) — cada método que transiciona `Order.stage` re-obtiene el
 * `Order` con `lockForUpdate()` DENTRO de la transacción (`ChangesOrderStage::lockOrder()`) y
 * vuelve a validar el estado contra esa copia bloqueada, nunca contra el `$order` que llegó como
 * parámetro (cargado sin lock por el controller) — mismo patrón que ya corrigió el CRÍTICO real de
 * `AdjustmentRequestController::approve()`/`reject()`.
 *
 * Re-revisión 2026-07-22 (hallazgo 🟡 no bloqueante) — el orden de adquisición de locks entre
 * `Order`/`OrderItem` propios y `ProductWarehouseStock`/`OrderItem` de otros pedidos competidores
 * no es el mismo en todos los services de Bodega, lo que puede producir un deadlock real (no
 * corrupción) detectado por Postgres cuando 2 pedidos DISTINTOS compiten por el mismo producto de
 * forma concurrente. Cada `DB::transaction()` que transiciona `Order.stage` pasa `$attempts = 3`
 * — Laravel reintenta automáticamente el closure completo si detecta un deadlock
 * (`Connection::causedByDeadlock()`), sin necesidad de unificar el orden de locks entre services.
 */
final class PickingService
{
    use ChangesOrderStage;

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly FamilyService $family,
    ) {}

    /** REQ-313 — Asignado -> Picking pendiente. RN1: picker obligatorio. RN3: quién puede asignar. */
    public function assignPicker(Order $order, int $pickerId, User $actor): Order
    {
        // Validaciones que NO dependen del estado del Order (rol del actor, validez del picker)
        // pueden resolverse antes de pagar el costo de abrir transacción — solo el chequeo de
        // `stage` necesita la copia bloqueada (Blocker 1).
        if (! in_array($actor->role?->key, BodegaRoles::CAN_OPERATE, true)) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega o los Asistentes de Bodega pueden asignar el picking.',
                status: 403,
            );
        }

        $picker = User::find($pickerId);
        if ($picker === null || $picker->role?->key !== BodegaRoles::PICKER || ! $picker->is_active) {
            throw new OrderTransitionException('El picker seleccionado no es válido.', ['picker_id' => ['El picker seleccionado no es válido.']]);
        }

        return DB::transaction(function () use ($order, $pickerId, $actor): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_ASIGNADO) {
                throw new OrderTransitionException('El pedido no está en la etapa Asignado.');
            }

            $locked->update(['picker_id' => $pickerId]);
            $this->transitionStage($locked, Order::STAGE_PICKING_PENDIENTE, $actor->id);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /**
     * REQ-314 — Picking pendiente -> En picking, dispara la reserva de stock (back-allocation,
     * REQ-322). PERMISOS del ticket (SCRUM-384): "Visible para todos los perfiles de Bodega" —
     * Jefe, Asistentes y el propio Picker (`BodegaRoles::CAN_PICK`), deliberadamente sin Courier
     * (Pre-QA 2026-07-26, ver docblock de `BodegaRoles::CAN_PICK`).
     */
    public function startPicking(Order $order, User $actor): Order
    {
        if (! in_array($actor->role?->key, BodegaRoles::CAN_PICK, true)) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega, los Asistentes de Bodega o el Picker asignado pueden operar el flujo de picking.',
                status: 403,
            );
        }

        return DB::transaction(function () use ($order, $actor): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_PICKING_PENDIENTE) {
                throw new OrderTransitionException('El pedido no está en la etapa Picking pendiente.');
            }
            if ($locked->picker_id === null) {
                throw new OrderTransitionException('El pedido no tiene un picker asignado.');
            }

            $this->transitionStage($locked, Order::STAGE_EN_PICKING, $actor->id);
            $this->reservations->reserveForOrder($locked);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /**
     * REQ-315 — edición de `qty_picked` mientras el pedido está "En picking" (RN2/RN3: fuera de
     * esa etapa la hoja es de solo consulta, gate que hace el controller antes de llamar acá).
     *
     * PERMISOS del ticket (SCRUM-385): "Visible para todos los perfiles de Bodega" — Jefe,
     * Asistentes y el propio Picker (`BodegaRoles::CAN_PICK`), deliberadamente sin Courier
     * (Pre-QA 2026-07-26, ver docblock de `BodegaRoles::CAN_PICK`).
     *
     * `picking_notes` (columna "Observación" del ticket original, agregada 2026-07-27 — ver
     * migración `..._100000_add_picking_notes_...`) es opcional por línea y respeta el mismo
     * gate de "solo editable en En picking" que `qty_picked` — no se crea un gate nuevo.
     *
     * @param list<array{order_item_id: int, qty_picked: int, picking_notes?: string|null}> $items
     */
    public function updatePickingSheet(Order $order, array $items, User $actor): Order
    {
        if (! in_array($actor->role?->key, BodegaRoles::CAN_PICK, true)) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega, los Asistentes de Bodega o el Picker asignado pueden operar el flujo de picking.',
                status: 403,
            );
        }

        return DB::transaction(function () use ($order, $items): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_EN_PICKING) {
                throw new OrderTransitionException('La Hoja de Picking solo es editable mientras el pedido está En picking.');
            }

            foreach ($items as $row) {
                /** @var OrderItem $item */
                $item = $locked->items()->lockForUpdate()->findOrFail($row['order_item_id']);
                if ($row['qty_picked'] < 0 || $row['qty_picked'] > $item->qty_requested) {
                    throw new OrderTransitionException(
                        'La cantidad alistada no puede ser negativa ni superar lo pedido.',
                        ['items' => ["order_item_id {$item->id}: cantidad alistada inválida."]],
                    );
                }

                $update = ['qty_picked' => $row['qty_picked']];
                if (array_key_exists('picking_notes', $row)) {
                    $update['picking_notes'] = $row['picking_notes'];
                }
                $item->update($update);
            }

            return $locked->fresh('items') ?? $locked;
        }, 3);
    }

    /**
     * REQ-316 — completa el picking. RN1: todos los artículos deben venir marcados como
     * "recogido" en el payload (no hay columna dedicada para ese booleano — el contrato del
     * endpoint es que `completePicking()` reciba SIEMPRE la lista completa de `order_items` del
     * pedido; si falta alguno, se interpreta como "no marcado" y se rechaza — decisión de diseño
     * documentada en el reporte de este batch, dentro del modelo de datos ya aprobado). RN2/RN3:
     * si todo coincide avanza a Packing; si algo quedó corto, dispara REQ-317 (tarjeta de Revisión
     * de Inventario, familia).
     *
     * Blocker 2 (Senior Review 2026-07-22) — `warehouse_id` de cada ítem NO se resuelve acá de
     * forma independiente: justo antes de leerlo se fuerza una pasada de
     * `StockReservationService::reallocateForProducts()` (misma transacción, mismos locks sobre
     * `product_warehouse_stock`/`order_items`) para asegurar la asignación por-bodega más fresca
     * posible, y después cada ítem simplemente HEREDA el `warehouse_id` que ese cálculo ya dejó
     * escrito — nunca se vuelve a preguntar "quién tiene más stock" en paralelo.
     *
     * PERMISOS del ticket (SCRUM-386): "Visible para todos los perfiles de Bodega" — Jefe,
     * Asistentes y el propio Picker (`BodegaRoles::CAN_PICK`), deliberadamente sin Courier
     * (Pre-QA 2026-07-26, ver docblock de `BodegaRoles::CAN_PICK`).
     *
     * @param list<array{order_item_id: int, qty_picked: int}> $items
     * @return array{order: Order, review: Order|null}
     */
    public function completePicking(Order $order, array $items, User $actor): array
    {
        if (! in_array($actor->role?->key, BodegaRoles::CAN_PICK, true)) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega, los Asistentes de Bodega o el Picker asignado pueden operar el flujo de picking.',
                status: 403,
            );
        }

        $submittedIds = collect($items)->pluck('order_item_id')->map(fn ($id) => (int) $id)->all();

        return DB::transaction(function () use ($order, $items, $submittedIds, $actor): array {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_EN_PICKING) {
                throw new OrderTransitionException('El pedido no está en la etapa En picking.');
            }

            $locked->loadMissing('items');
            $allIds  = $locked->items->pluck('id')->all();
            $missing = array_diff($allIds, $submittedIds);
            if ($missing !== []) {
                throw new OrderTransitionException(
                    'No se puede completar el picking: faltan artículos por marcar como recogidos.',
                    ['items' => ['Faltan artículos por marcar como recogidos.']],
                );
            }

            // Blocker 2 — asegura que warehouse_id/qty_reserved reflejen el reparto por-bodega más
            // fresco posible (lockeado) ANTES de leerlo/persistirlo en cada order_item.
            /** @var list<int> $productIds */
            $productIds = $locked->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
            $this->reservations->reallocateForProducts($productIds);

            foreach ($items as $row) {
                /** @var OrderItem $item */
                $item = $locked->items()->lockForUpdate()->findOrFail($row['order_item_id']);
                if ($row['qty_picked'] < 0 || $row['qty_picked'] > $item->qty_requested) {
                    throw new OrderTransitionException(
                        'La cantidad alistada no puede ser negativa ni superar lo pedido.',
                        ['items' => ["order_item_id {$item->id}: cantidad alistada inválida."]],
                    );
                }
                // warehouse_id NO se toca acá — ya lo dejó resuelto reallocateForProducts() arriba.
                $item->update(['qty_picked' => $row['qty_picked']]);
            }

            $locked->refresh();
            $locked->load('items');

            $shortfallItems = $locked->items->filter(fn (OrderItem $i): bool => $i->qty_picked < $i->qty_requested);

            $review = null;
            if ($shortfallItems->isNotEmpty()) {
                $review = $this->createInventoryReviewCard($locked, $shortfallItems, $actor);
            }

            // RN1 (REQ-317): la tarjeta original SIEMPRE continúa hacia Packing — con las
            // cantidades reducidas a lo realmente alistado (qty_picked), que es lo que Packing usa
            // como tope de entrega (ver DeliveryService::registerDelivery()).
            $this->transitionStage($locked, Order::STAGE_PACKING, $actor->id);

            event(new PickingCompleted(
                orderId: $locked->id,
                orderNumber: $locked->order_number,
                pickerId: (int) $locked->picker_id,
                tenant: $this->requiredTenantSlug(),
            ));

            // Segunda pasada: el pedido ahora está en Packing (FROZEN_STAGES) — esta llamada es la
            // que efectivamente congela warehouse_id/qty_reserved de sus ítems y libera a los demás
            // competidores el remanente que ya no van a usar (ítems con shortfall que se movieron
            // a la Revisión de Inventario, si aplica).
            /** @var list<int> $productIdsAfter */
            $productIdsAfter = $locked->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
            if ($review !== null) {
                /** @var list<int> $reviewProductIds */
                $reviewProductIds = $review->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
                $productIdsAfter = array_values(array_unique(array_merge($productIdsAfter, $reviewProductIds)));
            }
            $this->reservations->reallocateForProducts($productIdsAfter);

            return ['order' => $locked->fresh() ?? $locked, 'review' => $review];
        }, 3);
    }

    /** @param \Illuminate\Support\Collection<int, OrderItem> $shortfallItems */
    private function createInventoryReviewCard(Order $order, $shortfallItems, User $actor): Order
    {
        $review = Order::create([
            'order_number'            => $order->order_number,
            'pipeline_card_id'        => $order->pipeline_card_id,
            'order_type'              => Order::ORDER_TYPE_REVISION_INVENTARIO,
            'stage'                   => Order::STAGE_PICKING_PENDIENTE,
            // family_id fijado ACÁ, no en un update() posterior — ver docblock de FamilyService::attach().
            'family_id'               => $order->familyRootId(),
            'assistant_id'            => $order->assistant_id,
            'picker_id'               => $order->picker_id,
            'committed_delivery_date' => $order->committed_delivery_date,
            'customer_name'           => $order->customer_name,
            'customer_contact'        => $order->customer_contact,
            'delivery_address'        => $order->delivery_address,
        ]);

        foreach ($shortfallItems as $short) {
            $review->items()->create([
                'catalog_product_id'   => $short->catalog_product_id,
                'reference_snapshot'   => $short->reference_snapshot,
                'description_snapshot' => $short->description_snapshot,
                'qty_requested'        => $short->qty_requested - $short->qty_picked,
                'location_snapshot'    => $short->location_snapshot,
            ]);
        }

        OrderStageHistory::create([
            'order_id'   => $review->id,
            'from_stage' => null,
            'to_stage'   => Order::STAGE_PICKING_PENDIENTE,
            'changed_by' => null, // creación automática, sin usuario detrás del clic (REQ-317)
            'created_at' => now(),
        ]);

        $this->family->attach($order, $review);

        event(new OrderSplit(
            parentOrderId: $order->id,
            orderNumber: $order->order_number,
            childOrderIds: [$review->id],
            tenant: $this->requiredTenantSlug(),
        ));

        return $review->fresh('items') ?? $review;
    }
}
