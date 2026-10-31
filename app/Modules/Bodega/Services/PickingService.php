<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Events\PickingCompleted;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderItemAllocation;
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
        $this->assertCanOperatePicking($order, $actor);

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
     * SCRUM-1196 — cada línea acepta `allocations` (`[{warehouse_id, qty_picked}]`): de qué bodega(s) de venta se alista y cuánto. Sin `allocations`,
     * `qty_picked` sale de la bodega de despacho por defecto (Bodega Central), como propone el sistema.
     *
     * @param list<array{order_item_id: int, qty_picked: int, picking_notes?: string|null, allocations?: list<array{warehouse_id: int, qty_picked: int}>|null}> $items
     */
    public function updatePickingSheet(Order $order, array $items, User $actor): Order
    {
        $this->assertCanOperatePicking($order, $actor);

        return DB::transaction(function () use ($order, $items): Order {
            $locked = $this->lockOrder($order->id);
            if ($locked->stage !== Order::STAGE_EN_PICKING) {
                throw new OrderTransitionException('La Hoja de Picking solo es editable mientras el pedido está En picking.');
            }

            // SCRUM-1196 — la reserva por bodega debe estar fresca antes de validar contra ella (mismo orden de locks que completePicking()).
            $locked->loadMissing('items');
            /** @var list<int> $preProductIds */
            $preProductIds = $locked->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
            $this->reservations->reallocateForProducts($preProductIds);

            foreach ($items as $row) {
                /** @var OrderItem $item */
                $item = $locked->items()->lockForUpdate()->findOrFail($row['order_item_id']);
                if ($row['qty_picked'] < 0 || $row['qty_picked'] > $item->qty_requested) {
                    throw new OrderTransitionException(
                        'La cantidad alistada no puede ser negativa ni superar lo pedido.',
                        ['items' => ["order_item_id {$item->id}: cantidad alistada inválida."]],
                    );
                }

                $total  = $this->applyPicked($item, (int) $row['qty_picked'], $row['allocations'] ?? null);
                $update = ['qty_picked' => $total];
                if (array_key_exists('picking_notes', $row)) {
                    $update['picking_notes'] = $row['picking_notes'];
                }
                $item->update($update);
            }

            /** @var list<int> $sheetProductIds */
            $sheetProductIds = $locked->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
            $this->reservations->reallocateForProducts($sheetProductIds);

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
     * SCRUM-1196 — cada línea acepta `allocations` igual que la hoja (ver `updatePickingSheet()`).
     *
     * @param list<array{order_item_id: int, qty_picked: int, allocations?: list<array{warehouse_id: int, qty_picked: int}>|null}> $items
     * @return array{order: Order, review: Order|null}
     */
    public function completePicking(Order $order, array $items, User $actor): array
    {
        $this->assertCanOperatePicking($order, $actor);

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
                // warehouse_id/qty_reserved los dejó resueltos reallocateForProducts() arriba; acá solo se reparte lo alistado entre las bodegas de venta.
                $item->update(['qty_picked' => $this->applyPicked($item, (int) $row['qty_picked'], $row['allocations'] ?? null)]);
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

    /**
     * SCRUM-1196 — fija DE QUÉ BODEGA(S) se alistó una línea y cuánto, y devuelve el total alistado. Reglas del ticket:
     *  - solo bodegas `permite_venta` (Reclamos, Merma, Reserva Servicios y los demás exhibiciones nunca se ofrecen);
     *  - nunca más de lo que tiene una bodega: en la de por defecto, lo que el sistema le reservó a ESTE pedido; en otra bodega de venta (1 pieza de
     *    Sala de exhibición 1 que Bodega escoge), el saldo que no tienen reservado otros pedidos;
     *  - sin `$allocations`, todo `$qtyPicked` sale de la bodega por defecto (más lo que ya se había escogido a mano, que se conserva).
     * Lo que no se pueda alistar se deja en la línea como faltante (REQ-317); un exceso se rechaza con 422 antes de tocar nada.
     *
     * @param list<array{warehouse_id: int, qty_picked: int}>|null $allocations
     */
    private function applyPicked(OrderItem $item, int $qtyPicked, ?array $allocations): int
    {
        // Una línea personalizada (sin producto de Catálogo) no mueve stock ni usa bodega: solo se valida contra lo pedido.
        if ($item->catalog_product_id === null) {
            return $allocations !== null ? (int) collect($allocations)->sum(fn (array $a): int => max(0, (int) $a['qty_picked'])) : $qtyPicked;
        }

        $item->load('allocations');
        $ctx       = $this->reservations->warehouseContext();
        $defaultId = $ctx['defaultId'];
        $saleIds   = $ctx['saleIds'];

        /** @var array<int, int> $target bodega_id => cantidad alistada */
        $target = [];
        if ($allocations !== null) {
            foreach ($allocations as $row) {
                $warehouseId = (int) $row['warehouse_id'];
                $qty = (int) $row['qty_picked'];
                if ($qty < 0) {
                    throw new OrderTransitionException('La cantidad alistada no puede ser negativa ni superar lo pedido.', ['items' => ["order_item_id {$item->id}: cantidad alistada inválida."]]);
                }
                if ($qty > 0) {
                    $target[$warehouseId] = ($target[$warehouseId] ?? 0) + $qty;
                }
            }
        } else {
            $manualPicked = 0;
            foreach ($item->allocations->where('manual', true) as $allocation) {
                $target[(int) $allocation->warehouse_id] = max(0, (int) $allocation->qty_picked);
                $manualPicked += max(0, (int) $allocation->qty_picked);
            }
            $defaultPick = max(0, $qtyPicked - $manualPicked);
            if ($defaultPick > 0 && $defaultId !== null) {
                $target[$defaultId] = $defaultPick;
            } elseif ($defaultPick > 0) {
                $target[-1] = $defaultPick; // sin bodega por defecto definida: se rechaza abajo con un mensaje claro
            }
        }

        $total = array_sum($target);
        if ($allocations !== null && $total !== $qtyPicked) {
            throw new OrderTransitionException(
                'La cantidad alistada no coincide con la suma del reparto por bodega.',
                ['items' => ["order_item_id {$item->id}: qty_picked ({$qtyPicked}) distinto de la suma de allocations ({$total})."]],
            );
        }
        if ($total > $item->qty_requested) {
            throw new OrderTransitionException('La cantidad alistada no puede ser negativa ni superar lo pedido.', ['items' => ["order_item_id {$item->id}: cantidad alistada inválida."]]);
        }

        $names = $ctx['names'];
        foreach ($target as $warehouseId => $qty) {
            if ($warehouseId === -1 || ! isset($names[$warehouseId])) {
                throw new OrderTransitionException(
                    $warehouseId === -1 ? 'No hay una bodega de despacho por defecto definida en Configuración de Bodega.' : 'La bodega indicada no existe.',
                    ['items' => ["order_item_id {$item->id}: bodega inválida."]],
                );
            }
            if (! in_array($warehouseId, $saleIds, true)) {
                $label = $names[$warehouseId];
                throw new OrderTransitionException(
                    "La bodega «{$label}» no está habilitada para ventas: solo se puede alistar desde las bodegas de venta.",
                    ['items' => ["order_item_id {$item->id}: bodega no permitida para ventas."]],
                );
            }

            // Mismo tope en TODAS las bodegas de venta: el saldo que no tienen reservado OTROS pedidos (el propio pedido no se descuenta a sí mismo, así que
            // volver de «10 Central + 1 Sala de exhibición 1» a «11 Central» no queda atrapado por la reserva ya recortada).
            $cap = $item->catalog_product_id !== null
                ? $this->reservations->availableAtWarehouse((int) $item->catalog_product_id, $warehouseId, $item->id, lock: true)
                : 0;

            if ($qty > $cap) {
                $label = $names[$warehouseId] ?? (string) $warehouseId;
                throw new OrderTransitionException(
                    "No hay saldo suficiente en «{$label}»: se puede alistar hasta {$cap} y se intentó {$qty}. Deja el resto como faltante o ajusta el inventario de esa bodega.",
                    ['items' => ["order_item_id {$item->id}: supera el saldo de «{$label}» ({$cap})."]],
                );
            }
        }

        // Persistir: la bodega por defecto es la asignación automática; las demás son escogidas por Bodega (`manual`).
        foreach ($item->allocations as $allocation) {
            $warehouseId = (int) $allocation->warehouse_id;
            if (! array_key_exists($warehouseId, $target)) {
                if ($allocation->manual) {
                    $allocation->delete();
                } elseif ($allocation->qty_picked !== 0) {
                    $allocation->update(['qty_picked' => 0]);
                }
            }
        }
        foreach ($target as $warehouseId => $qty) {
            $existing = $item->allocations->first(fn (OrderItemAllocation $al): bool => (int) $al->warehouse_id === $warehouseId);
            $isDefault = $warehouseId === $defaultId;
            if ($existing !== null) {
                $existing->update(['qty_picked' => $qty] + ($isDefault ? [] : ['qty_reserved' => $qty, 'manual' => true]));
            } else {
                OrderItemAllocation::create([
                    'order_item_id' => $item->id, 'warehouse_id' => $warehouseId, 'qty_reserved' => $qty, 'qty_picked' => $qty, 'manual' => ! $isDefault,
                ]);
            }
        }
        $item->unsetRelation('allocations');

        return $total;
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
                // SCRUM-929 — el ítem derivado hereda vínculo a la cotización y precio del padre;
                // antes nacía con unit_price NULL.
                'quote_item_id'        => $short->quote_item_id,
                'unit_price'           => $short->unit_price,
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

    /**
     * Fase 16 (5.4) — Jefe y Asistentes de Bodega operan el picking de cualquier pedido (también
     * pueden reasignarlo); el Picker solo el de los pedidos que tiene asignados. Antes bastaba el
     * rol, y un Picker no asignado podía iniciar/editar/completar el picking de otro.
     */
    private function assertCanOperatePicking(Order $order, User $actor): void
    {
        $role = $actor->role?->key;
        $esLider = in_array($role, BodegaRoles::CAN_OPERATE, true);
        $esPickerAsignado = $role === BodegaRoles::PICKER && $order->picker_id !== null && (int) $order->picker_id === (int) $actor->id;

        if (! $esLider && ! $esPickerAsignado) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega, los Asistentes de Bodega o el Picker asignado a este pedido pueden operar su picking.',
                status: 403,
            );
        }
    }
}
