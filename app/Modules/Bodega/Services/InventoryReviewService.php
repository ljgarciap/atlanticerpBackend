<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Events\InventoryReviewResolved;
use App\Modules\Bodega\Events\OrderShortageConfirmed;
use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Services\Concerns\ChangesOrderStage;
use App\Modules\Bodega\Support\BodegaRoles;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-318) — resuelve una tarjeta "Revisión de Inventario"
 * producto por producto (Pendiente/Sí hay/No hay), con 3 desenlaces:
 *   RN1 — todo encontrado: la misma tarjeta pasa directo a "En picking" (sin volver a la cola).
 *   RN2 — nada encontrado: la misma tarjeta se marca "Faltante" y se queda en "Asignado", se
 *         notifica a Compras y a Ventas & Diseño.
 *   RN3 — mixto: se separa en 2 tarjetas — una nueva con lo encontrado (a "En picking") y la
 *         original se queda con lo no encontrado (a "Faltante"/"Asignado") — ambas en la misma
 *         familia (REQ-319).
 *
 * Diseño de datos (decisión de este batch, dentro del modelo ya aprobado): "Faltante confirmado"
 * se representa como `order_type = Order::ORDER_TYPE_FALTANTE` + `stage = Asignado` — mismo tipo
 * que usa `DeliveryService::registerDelivery()` para el faltante que sale de Packing (REQ-325),
 * así el chip "📦 Sin stock" (REQ-310 RN3) tiene una sola definición para las 2 fuentes posibles.
 */
final class InventoryReviewService
{
    use ChangesOrderStage;

    /**
     * `role_key` de Compras que debe reordenar el producto (Lider Compras Test, ver `BusinessRoleUserSeeder`)
     * — mismo criterio que `OrderService::BODEGA_LEAD_ROLE_KEY`: es un ROL, no una persona
     * puntual configurable.
     */
    private const COMPRAS_LEAD_ROLE_KEY = 'lider_compras';

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly FamilyService $family,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Senior Review 2026-07-22 (Blocker 1) — el chequeo de `order_type`/`stage` vive DENTRO de la
     * transacción, contra una copia de `Order` re-obtenida con `lockForUpdate()`
     * (`ChangesOrderStage::lockOrder()`), nunca contra el `$review` que llegó como parámetro
     * (cargado sin lock por el controller) — mismo patrón que ya corrigió el CRÍTICO real de
     * `AdjustmentRequestController::approve()`/`reject()`. La validación de forma del payload
     * (ubicación obligatoria si `found=true`) no depende del estado del pedido y puede resolverse
     * antes de abrir la transacción.
     *
     * Re-revisión 2026-07-22 (hallazgo 🟡 no bloqueante) — `$attempts = 3` en el `DB::transaction()`
     * de abajo: el orden de locks (`OrderItem` propio antes que `ProductWarehouseStock`/`OrderItem`
     * ajeno vía `reserveForOrder()`) puede deadlockear contra otro pedido que compite por el mismo
     * producto en orden inverso (`PickingService::completePicking()`) — Laravel reintenta el
     * closure completo si Postgres lo detecta, sin necesidad de unificar el orden entre services.
     *
     * Pre-QA 2026-07-22 (criterio confirmado contra Jira, REQ-318 "PERMISOS: Asistentes y Jefe de
     * Bodega") — a diferencia de otros pasos del flujo que sí son "visibles para todos los
     * perfiles de Bodega" (ej. completePicking), resolver la Revisión de Inventario está acotado
     * al mismo `CAN_OPERATE` que `assignPicker` — el Picker (`ayudante_general_bodega`) y el
     * Repartidor (`transporte`) NO deberían poder resolverla aunque tengan `bodega.write`.
     *
     * @param  list<array{order_item_id: int, found: bool, location?: string|null}>  $decisions
     * @return array{review: Order, newOrder: Order|null}
     */
    public function resolve(Order $review, array $decisions, User $actor): array
    {
        if (! in_array($actor->role?->key, BodegaRoles::CAN_OPERATE, true)) {
            throw new OrderTransitionException(
                'Solo el Jefe de Bodega o los Asistentes de Bodega pueden resolver la Revisión de Inventario.',
                status: 403,
            );
        }

        foreach ($decisions as $d) {
            if ($d['found'] && trim((string) ($d['location'] ?? '')) === '') {
                throw new OrderTransitionException(
                    'Falta indicar dónde se encontró el artículo.',
                    ['items' => ["order_item_id {$d['order_item_id']}: falta la ubicación."]],
                );
            }
        }

        $submittedIds = collect($decisions)->pluck('order_item_id')->map(fn ($id) => (int) $id)->all();

        return DB::transaction(function () use ($review, $decisions, $submittedIds, $actor): array {
            $locked = $this->lockOrder($review->id);
            if ($locked->order_type !== Order::ORDER_TYPE_REVISION_INVENTARIO) {
                throw new OrderTransitionException('Esta tarjeta no es una Revisión de Inventario.');
            }
            if ($locked->stage !== Order::STAGE_PICKING_PENDIENTE) {
                throw new OrderTransitionException('Esta Revisión de Inventario ya fue resuelta.');
            }

            $locked->loadMissing('items');
            $allIds = $locked->items->pluck('id')->all();
            if (array_diff($allIds, $submittedIds) !== []) {
                throw new OrderTransitionException(
                    'Faltan artículos por resolver en la Revisión de Inventario.',
                    ['items' => ['Faltan artículos por resolver.']],
                );
            }

            $foundIds = [];
            $notFoundIds = [];

            foreach ($decisions as $d) {
                /** @var OrderItem $item */
                $item = $locked->items()->lockForUpdate()->findOrFail($d['order_item_id']);
                if ($d['found']) {
                    // Corrección 2026-08-11 (Gerencia Test, SCRUM-388) — `location_snapshot` es la
                    // ubicación ORIGINAL del producto y nunca se sobrescribe desde este flujo; la
                    // ubicación nueva/confirmada durante la revisión vive en `found_location`
                    // (+ quién/cuándo la confirmó), ver docblock de `OrderItem::foundNote()`.
                    $item->update([
                        'found_at_location' => true,
                        'found_location' => $d['location'] ?? null,
                        'found_confirmed_by_id' => $actor->id,
                        'found_confirmed_at' => now(),
                    ]);
                    $foundIds[] = $item->id;
                } else {
                    $item->update(['found_at_location' => false]);
                    $item->setShortageReason(OrderItem::SHORTAGE_SIN_STOCK);
                    $notFoundIds[] = $item->id;

                    event(new OrderShortageConfirmed(
                        orderId: $locked->id,
                        orderNumber: $locked->order_number,
                        orderItemId: $item->id,
                        tenant: $this->requiredTenantSlug(),
                    ));
                }
            }

            $newOrder = null;

            if ($notFoundIds === []) {
                // RN1 — todo encontrado.
                $locked->update(['order_type' => Order::ORDER_TYPE_PEDIDO]);
                $this->transitionStage($locked, Order::STAGE_EN_PICKING, $actor->id);
                $locked->refresh();
                $this->reservations->reserveForOrder($locked);
            } elseif ($foundIds === []) {
                // RN2 — nada encontrado: la propia tarjeta queda como faltante en Asignado.
                $locked->update(['order_type' => Order::ORDER_TYPE_FALTANTE]);
                $this->transitionStage($locked, Order::STAGE_ASIGNADO, $actor->id);
                $this->notifyShortage($locked, $actor);
            } else {
                // RN3 — mixto: nueva tarjeta con lo encontrado, la original se queda con lo faltante.
                $newOrder = $this->splitFoundItemsIntoNewOrder($locked, $foundIds, $actor);
                $locked->update(['order_type' => Order::ORDER_TYPE_FALTANTE]);
                $this->transitionStage($locked, Order::STAGE_ASIGNADO, $actor->id);
                $this->notifyShortage($locked, $actor);
                $this->reservations->reserveForOrder($newOrder);
            }

            event(new InventoryReviewResolved(
                orderId: $locked->id,
                orderNumber: $locked->order_number,
                resolvedBy: $actor->id,
                tenant: $this->requiredTenantSlug(),
            ));

            return ['review' => $locked->fresh('items') ?? $locked, 'newOrder' => $newOrder?->fresh('items')];
        }, 3);
    }

    /** @param list<int> $foundIds */
    private function splitFoundItemsIntoNewOrder(Order $review, array $foundIds, User $actor): Order
    {
        $newOrder = Order::create([
            'order_number' => $review->order_number,
            'pipeline_card_id' => $review->pipeline_card_id,
            'order_type' => Order::ORDER_TYPE_PEDIDO,
            'stage' => Order::STAGE_EN_PICKING,
            // family_id fijado ACÁ, no en un update() posterior — ver docblock de FamilyService::attach().
            'family_id' => $review->familyRootId(),
            'assistant_id' => $review->assistant_id,
            'picker_id' => $review->picker_id,
            'committed_delivery_date' => $review->committed_delivery_date,
            'customer_name' => $review->customer_name,
            'customer_contact' => $review->customer_contact,
            'delivery_address' => $review->delivery_address,
        ]);

        foreach ($review->items()->whereIn('id', $foundIds)->get() as $foundItem) {
            $newOrder->items()->create([
                'catalog_product_id' => $foundItem->catalog_product_id,
                // SCRUM-929 — el ítem original se borra justo abajo y se recrea en la tarjeta
                // nueva: el vínculo a la cotización y el precio tienen que sobrevivir al split.
                'quote_item_id' => $foundItem->quote_item_id,
                'unit_price' => $foundItem->unit_price,
                'reference_snapshot' => $foundItem->reference_snapshot,
                'description_snapshot' => $foundItem->description_snapshot,
                'qty_requested' => $foundItem->qty_requested,
                'location_snapshot' => $foundItem->location_snapshot,
                'found_at_location' => true,
                // Corrección 2026-08-11 (SCRUM-388) — la nueva tarjeta "En picking" hereda la
                // ubicación confirmada durante la revisión, para que el picker la use como
                // referencia real (ver criterio de aceptación "al volver a hacer el picking, el
                // picker puede utilizar Encontrado en").
                'found_location' => $foundItem->found_location,
                'found_confirmed_by_id' => $foundItem->found_confirmed_by_id,
                'found_confirmed_at' => $foundItem->found_confirmed_at,
            ]);
            $foundItem->delete();
        }

        OrderStageHistory::create([
            'order_id' => $newOrder->id,
            'from_stage' => null,
            'to_stage' => Order::STAGE_EN_PICKING,
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);

        $this->family->attach($review, $newOrder);

        event(new OrderSplit(
            parentOrderId: $review->id,
            orderNumber: $review->order_number,
            childOrderIds: [$newOrder->id],
            tenant: $this->requiredTenantSlug(),
        ));

        return $newOrder;
    }

    /**
     * REQ-318 RN2 — notifica a Compras (reordenar, todo el rol `lider_compras`) y al vendedor
     * original del pedido en Ventas & Diseño (informar retraso al cliente — no a TODOS los
     * vendedores, solo al dueño de la tarjeta de Pipeline de la que nació este pedido).
     */
    private function notifyShortage(Order $order, User $actor): void
    {
        $comprasRoleId = Role::where('key', self::COMPRAS_LEAD_ROLE_KEY)->value('id');
        /** @var list<int> $comprasAudience */
        $comprasAudience = $comprasRoleId !== null
            ? User::where('role_id', $comprasRoleId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];

        /** @var PipelineCard|null $pipelineCard */
        $pipelineCard = $order->pipeline_card_id !== null ? PipelineCard::find($order->pipeline_card_id) : null;
        // El dueño de la tarjeta puede ser también del rol de Compras; no se le manda dos veces.
        $sellerAudience = $pipelineCard !== null && ! in_array($pipelineCard->owner_id, $comprasAudience, true)
            ? [$pipelineCard->owner_id]
            : [];

        $channels = [NotificationSend::CHANNEL_IN_APP, NotificationSend::CHANNEL_EMAIL];
        $subject = "Pedido {$order->order_number} sin stock disponible";

        // SCRUM-1153 — Compras (Yirena) recibe su propio aviso: con el/los producto(s) que faltan y un enlace a Compras →
        // Inventario con ese producto ya buscado, desde donde genera la orden de compra. Antes la notificación no llevaba
        // enlace (clic = nada) y Compras no tiene el módulo Bodega, así que tampoco podía ir a buscar el pedido.
        if ($comprasAudience !== []) {
            /** @var Collection<int, OrderItem> $shortItems */
            $shortItems = $order->items()->orderBy('id')->get();
            $refs = $shortItems->map(fn (OrderItem $i): string => trim((string) ($i->reference_snapshot ?? $i->catalogProduct->reference ?? '')))
                ->filter(fn (string $r): bool => $r !== '')->values();
            // Pre-QA: un ítem personalizado (sin producto de catálogo ni referencia) se nombra por su descripción, no «—».
            $detail = $shortItems->map(function (OrderItem $i): string {
                $name = trim((string) ($i->reference_snapshot ?? $i->catalogProduct->reference ?? ''));
                if ($name === '') {
                    $name = trim((string) $i->description_snapshot) !== '' ? trim((string) $i->description_snapshot) : 'Producto personalizado';
                }

                return "{$name} (x{$i->qty_requested})";
            })->implode(', ');

            $content = [
                'subject' => $subject,
                'body' => "El pedido {$order->order_number} quedó sin stock tras la revisión de inventario en Bodega — "
                    ."requiere reordenar el producto. Faltan: {$detail}."
                    .($refs->count() > 1 ? ' El enlace abre el primero; busque los demás por referencia.' : ''),
            ];
            if ($refs->isNotEmpty()) {
                $content['cta_url'] = '/inventario?search='.rawurlencode($refs->first());
            }

            $this->notifications->send(
                audience: $comprasAudience,
                content: $content,
                channels: $channels,
                source: NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE,
                sourceRef: "order:{$order->id}",
                createdBy: $actor->id,
            );
        }

        // Ventas & Diseño (dueño de la tarjeta) — mismo aviso de siempre, sin cambios: informar al cliente del retraso.
        if ($sellerAudience !== []) {
            // SCRUM-1159 — el vendedor no reconoce un ID de pedido: se agrega el N° (folio) de la cotización de origen y un enlace a
            // «Status de Pedidos» (solo lectura, ve únicamente lo propio) con el pedido ya buscado.
            $quoteFolio = trim((string) $order->linkedQuote()?->folio);
            $quoteLabel = $quoteFolio !== '' ? " (cotización {$quoteFolio})" : '';

            $this->notifications->send(
                audience: $sellerAudience,
                content: [
                    'subject' => "Pedido {$order->order_number}{$quoteLabel} sin stock disponible",
                    'body' => "El pedido {$order->order_number}{$quoteLabel} quedó sin stock tras la revisión de inventario "
                        .'en Bodega — requiere reordenar el producto (Compras) e informar al cliente del retraso '
                        .'(Ventas & Diseño).',
                    'cta_url' => '/bodega/pedidos/status?search='.rawurlencode((string) $order->order_number),
                ],
                channels: $channels,
                source: NotificationSend::SOURCE_BODEGA_ORDER_SHORTAGE,
                sourceRef: "order:{$order->id}",
                createdBy: $actor->id,
            );
        }
    }
}
