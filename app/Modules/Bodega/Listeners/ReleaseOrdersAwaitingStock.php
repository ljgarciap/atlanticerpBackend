<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Listeners;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Compras\Events\PurchaseOrderReceived;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-406 RN2 (ADR `docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md`) — puente Compras ->
 * Bodega: cuando una `PurchaseOrder` llega a "Recibido", libera las tarjetas "Sin stock"
 * (`Order::isAwaitingStock()`) cuyo stock real ya alcanza. Registrado en
 * `App\Providers\AppServiceProvider::boot()`, despachado síncronamente dentro del mismo request
 * de `PurchaseOrderController::advance()` — mismo criterio que el antiguo listener de creación de pedido (eliminado en
 * SCRUM-1128, hoy el pedido nace de `PipelineService::passToOrder()`): el
 * efecto debe ser inmediato (no `ShouldQueue`), y un fallo del lado de Bodega nunca debe romper
 * el flujo de Compras que lo dispara — por eso TODO `handle()` queda envuelto en try/catch +
 * `Log::error()`.
 *
 * Matching por `catalog_product_id` — no existe FK entre `OrderItem` y `PurchaseOrderLine`, mismo
 * criterio ya validado por Pre-QA para el Escenario 1 de REQ-336 (ver
 * `OrderBoardService::supplierEta()`). FIFO por `updated_at` ascendente: `Order` no tiene una
 * columna `status_changed_at` propia (a diferencia de `PurchaseOrder`) — `updated_at` es el
 * proxy real de "desde cuándo espera", porque nada más actualiza la fila mientras el pedido
 * permanece faltante+asignado. Sin liberación parcial: si un pedido tiene ítems faltantes de MÁS
 * de un producto, todos deben quedar cubiertos por el stock real disponible para liberar ese
 * pedido completo — de lo contrario se deja intacto.
 *
 * Limitación conocida, documentada en el ADR y no introducida por este listener: no existe
 * sistema de reserva de stock para pedidos "faltante" (a diferencia del flujo normal, que sí
 * reserva vía `StockReservationService::reserveForOrder()`) — si 2 pedidos compiten por el mismo
 * stock recién llegado, gana el que la query ordena primero (FIFO por tiempo de espera).
 */
class ReleaseOrdersAwaitingStock
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PurchaseOrderReceived $event): void
    {
        try {
            $this->release($event);
        } catch (Throwable $e) {
            Log::error('bodega.order.stock-release-failed', [
                'purchase_order_id' => $event->purchaseOrderId,
                'product_ids'       => $event->productIds,
                'tenant'            => $event->tenant,
                'exception'         => $e->getMessage(),
                'exception_class'   => $e::class,
            ]);
        }
    }

    private function release(PurchaseOrderReceived $event): void
    {
        if ($event->productIds === []) {
            return;
        }

        DB::transaction(function () use ($event): void {
            /** @var Collection<int, Order> $candidates */
            $candidates = Order::query()
                ->where('order_type', Order::ORDER_TYPE_FALTANTE)
                ->where('stage', Order::STAGE_ASIGNADO)
                ->whereHas('items', fn (Builder $q) => $q
                    ->whereIn('catalog_product_id', $event->productIds)
                    ->where('shortage_reason', OrderItem::SHORTAGE_SIN_STOCK)
                    ->where('found_at_location', false))
                ->with('items')
                ->orderBy('updated_at')
                ->lockForUpdate()
                ->get();

            if ($candidates->isEmpty()) {
                return;
            }

            // Todos los productos con algún ítem faltante entre los candidatos -- no solo los de
            // $event->productIds -- para poder evaluar la cobertura COMPLETA de un pedido con
            // ítems faltantes de más de un producto (uno de ellos puede ya tener stock suficiente
            // desde antes de este evento, sin depender de que esta PO puntual lo haya traído).
            /** @var list<int> $allProductIds */
            $allProductIds = $candidates
                ->flatMap(fn (Order $order) => $order->items)
                ->filter(fn (OrderItem $item) => $item->catalog_product_id !== null
                    && $item->shortage_reason === OrderItem::SHORTAGE_SIN_STOCK
                    && $item->found_at_location === false)
                ->pluck('catalog_product_id')
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($allProductIds === []) {
                return;
            }

            /** @var array<int, int> $remainingStock catalog_product_id => stock disponible, descontado en memoria a medida que se cubren pedidos en esta misma pasada */
            $remainingStock = CatalogProduct::query()
                ->whereIn('id', $allProductIds)
                ->lockForUpdate()
                ->pluck('stock_quantity', 'id')
                ->map(fn ($qty) => (int) ($qty ?? 0))
                ->all();

            foreach ($candidates as $order) {
                $shortageItems = $order->items->filter(
                    fn (OrderItem $item) => $item->catalog_product_id !== null
                        && $item->shortage_reason === OrderItem::SHORTAGE_SIN_STOCK
                        && $item->found_at_location === false,
                );

                if ($shortageItems->isEmpty()) {
                    continue;
                }

                /** @var array<int, int> $required catalog_product_id => cantidad pendiente total de este pedido */
                $required = [];
                foreach ($shortageItems as $item) {
                    $productId = (int) $item->catalog_product_id;
                    $required[$productId] = ($required[$productId] ?? 0) + $item->pendingQty();
                }

                $canCoverAll = true;
                foreach ($required as $productId => $qty) {
                    if (($remainingStock[$productId] ?? 0) < $qty) {
                        $canCoverAll = false;
                        break;
                    }
                }

                // Sin liberación parcial (ADR): si algún producto faltante de este pedido no está
                // cubierto todavía, el pedido entero se queda tal cual estaba.
                if (! $canCoverAll) {
                    continue;
                }

                foreach ($required as $productId => $qty) {
                    $remainingStock[$productId] -= $qty;
                }

                foreach ($shortageItems as $item) {
                    $item->update(['found_at_location' => true]);
                }

                // Solo se revierte order_type -- el pedido sigue en STAGE_ASIGNADO, listo para el
                // flujo normal de picking (el criterio de aceptación solo exige que deje de
                // mostrarse "sin stock", no que avance de etapa por sí solo).
                $order->update(['order_type' => Order::ORDER_TYPE_PEDIDO]);

                $this->notifyReleased($order);
            }
        });
    }

    /** REQ-336 RN2 — avisa al asistente de Bodega asignado y, si aplica, al dueño de la tarjeta de Pipeline de origen (mismo criterio de audiencia que `InventoryReviewService::notifyShortage()`, en dirección inversa). */
    private function notifyReleased(Order $order): void
    {
        /** @var list<int> $audience */
        $audience = [];
        if ($order->assistant_id !== null) {
            $audience[] = $order->assistant_id;
        }

        /** @var PipelineCard|null $pipelineCard */
        $pipelineCard = $order->pipeline_card_id !== null ? PipelineCard::find($order->pipeline_card_id) : null;
        if ($pipelineCard !== null) {
            $audience[] = $pipelineCard->owner_id;
        }

        $audience = array_values(array_unique($audience));
        if ($audience === []) {
            return;
        }

        $this->notifications->send(
            audience: $audience,
            content: [
                'subject' => "Pedido {$order->order_number} ya tiene stock disponible",
                'body'    => "El pedido {$order->order_number} salió de \"Sin stock\": la orden de compra ".
                    'que cubría el faltante ya fue recibida. Puede continuar con el flujo normal de picking.',
                // SCRUM-1167 — el clic de la campana lleva al pedido (Status de Pedidos, ya filtrado por su número).
                'cta_url' => '/bodega/pedidos/status?search='.rawurlencode((string) $order->order_number),
            ],
            channels: [NotificationSend::CHANNEL_IN_APP, NotificationSend::CHANNEL_EMAIL],
            source: NotificationSend::SOURCE_BODEGA_ORDER_STOCK_RELEASED,
            sourceRef: "order:{$order->id}",
        );
    }
}
