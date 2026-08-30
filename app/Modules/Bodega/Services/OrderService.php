<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Events\OrderCreated;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardContact;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307/308) — crea la tarjeta de Pedido en Bodega cuando una
 * cotización pasa a "Aprobado" en Ventas & Diseño. Único punto de entrada de esta lógica: el
 * listener `App\Modules\Bodega\Listeners\CreateOrderFromApprovedQuote` solo delega acá.
 */
final class OrderService
{
    /**
     * `role_key` real del catálogo de roles (`roles.key`, ver `BusinessRoleUserSeeder`) — Esteban
     * Cardenas hoy. No hay un settings configurable dedicado (a diferencia de "Mark" en Compras,
     * `ComprasSettings.mark_approver_user_id`) porque acá la audiencia es un ROL, no una persona
     * puntual configurable — cualquiera con este rol recibe la alerta, sin necesidad de un campo
     * de configuración aparte (decisión 2026-07-22, revisión de Luis sobre el batch).
     */
    private const BODEGA_LEAD_ROLE_KEY = 'lider_bodega';

    public function __construct(
        private readonly OrderFolioService $folioService,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * REQ-308: Escenario 1 — "aparece automáticamente una tarjeta nueva en Pedidos, en
     * 'Asignado', con los datos correctos y ya asignada al asistente correcto".
     *
     * Caso borde (REQ-307, revisado 2026-07-22): si el vendedor de la tarjeta no tiene un
     * `WarehouseAssistantMapping` activo, el pedido SE CREA IGUAL (en "Asignado", `assistant_id
     * = null`) — decisión explícita de Luis: "no perder ningún pedido" (REQ-308, valor de
     * negocio) pesa más que "siempre asignado a alguien". Se dispara `OrderCreated` igual, y se
     * notifica al Jefe de Bodega (rol `lider_bodega`) para que lo asigne manualmente — ver
     * `notifyBodegaLeadOfUnassignedOrder()`.
     *
     * Solo retorna null si la tarjeta de Pipeline no existe (precondición real, no un caso de
     * negocio: no hay nada de qué heredar datos).
     */
    public function createFromApprovedQuote(int $pipelineCardId, int $sellerId): ?Order
    {
        $card = PipelineCard::with(['contacts', 'subClient', 'masterClient', 'salesProject', 'deliveryDates'])->find($pipelineCardId);

        if ($card === null) {
            Log::warning('bodega.order.creation-skipped-pipeline-card-not-found', [
                'pipeline_card_id' => $pipelineCardId,
            ]);

            return null;
        }

        $mapping = WarehouseAssistantMapping::where('seller_user_id', $sellerId)
            ->where('is_active', true)
            ->first();

        $quote = $this->resolveApprovedQuote($card);

        if ($quote === null) {
            // No debería pasar en el flujo normal (el gate proposal->approved de PipelineCard
            // exige comprobante de aprobación, lo que implica que la cotización ya se generó),
            // pero REQ-308 valora explícitamente "no perder ningún pedido" por sobre "no crear un
            // pedido incompleto" — se crea igual, sin ítems, y se loggea para investigar.
            Log::warning('bodega.order.created-without-quote-items', [
                'pipeline_card_id' => $pipelineCardId,
                'sales_project_id' => $card->sales_project_id,
            ]);
        }

        return DB::transaction(function () use ($card, $quote, $mapping, $sellerId): Order {
            $primaryContact = $card->contacts->first();

            $order = Order::create([
                'order_number'            => $this->folioService->next(),
                'pipeline_card_id'        => $card->id,
                'order_type'              => Order::ORDER_TYPE_PEDIDO,
                'stage'                   => Order::STAGE_ASIGNADO,
                'assistant_id'            => $mapping?->assistant_user_id,
                'committed_delivery_date' => $card->deliveryDates->min('date'),
                'customer_name'           => $card->subClient->business_name ?? $card->masterClient?->name,
                'customer_contact'        => $this->formatContact($primaryContact),
                'delivery_address'        => $card->subClient?->delivery_address,
            ]);

            if ($quote !== null) {
                $this->createItemsFromQuote($order, $quote);
            }

            OrderStageHistory::create([
                'order_id'   => $order->id,
                'from_stage' => null,
                'to_stage'   => Order::STAGE_ASIGNADO,
                'changed_by' => null, // creación automática del sistema, sin usuario detrás del clic
                'created_at' => now(),
            ]);

            event(new OrderCreated(
                orderId: $order->id,
                orderNumber: $order->order_number,
                assistantId: $order->assistant_id,
                tenant: $this->requiredTenantSlug(),
            ));

            if ($mapping === null) {
                Log::warning('bodega.order.created-without-assistant-mapping', [
                    'order_id'       => $order->id,
                    'order_number'   => $order->order_number,
                    'seller_user_id' => $sellerId,
                ]);

                $this->notifyBodegaLeadOfUnassignedOrder($order, $sellerId);
            }

            return $order;
        });
    }

    /**
     * REQ-308 RN1: hereda cliente/proyecto/vendedor/productos/dirección/contacto "de la
     * cotización aprobada". No hay FK directa cotización->tarjeta (`Quote::linkedPipelineCard()`
     * es una convención de negocio, resuelta por búsqueda) — acá se hace el camino inverso:
     * de la tarjeta a SU cotización, tomando la más reciente que ya tenga folio (generada), y
     * si no hay ninguna generada, la más reciente sin importar folio (mejor un borrador que nada).
     *
     * Público desde SCRUM-786: mismo camino que reusa `BodegaBackfillOrderItemPricesCommand` para
     * resolver, de forma best-effort, la cotización de origen de un `Order` ya existente.
     */
    public function resolveApprovedQuote(PipelineCard $card): ?Quote
    {
        if ($card->sales_project_id === null) {
            return null;
        }

        $withRelations = ['parts.items.catalogProduct'];

        return Quote::with($withRelations)
                ->where('sales_project_id', $card->sales_project_id)
                ->whereNotNull('folio')
                ->orderByDesc('generated_at')
                ->first()
            ?? Quote::with($withRelations)
                ->where('sales_project_id', $card->sales_project_id)
                ->orderByDesc('created_at')
                ->first();
    }

    /**
     * SCRUM-786: `unit_price` se snapshotea acá (precio efectivo del ítem YA con tipo de
     * precio/descuento resueltos, `QuoteItem::unitPriceAfterDiscount()`) — es el único punto en
     * todo el flujo Cotización→Pedido→Devolución donde el `QuoteItem` de origen está disponible
     * directamente, sin tener que re-matchearlo después por convención (ver
     * `BodegaBackfillOrderItemPricesCommand` para el caso de pedidos que no pasaron por acá).
     * `$settings` se resuelve una sola vez fuera del loop, mismo criterio anti-N+1 que
     * `QuoteItem::effectiveUnitPrice()`.
     */
    private function createItemsFromQuote(Order $order, Quote $quote): void
    {
        $items = $quote->parts->flatMap(fn (QuotePart $part) => $part->items);
        $settings = PricingSettings::current();

        /** @var QuoteItem $item */
        foreach ($items as $item) {
            $order->items()->create([
                'catalog_product_id'   => $item->catalog_product_id,
                'reference_snapshot'   => $item->catalogProduct->reference ?? $item->reference,
                'description_snapshot' => $item->catalogProduct->name ?? $item->description,
                'unit_price'           => $item->unitPriceAfterDiscount(
                    $quote->discount_mode,
                    $quote->global_discount_percent,
                    $quote->price_type,
                    $settings,
                ),
                'qty_requested'        => (int) round($item->quantity),
            ]);
        }
    }

    private function formatContact(?PipelineCardContact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        $detail = $contact->phone ?? $contact->email;

        return $detail !== null ? "{$contact->name} ({$detail})" : $contact->name;
    }

    /**
     * Reusa el módulo genérico `App\Modules\Notifications` (mismo mecanismo que
     * `EvaluateNotificationRulesListener` de CRM) en vez de un canal propio — audiencia = todos
     * los usuarios activos con `role_id` del rol `lider_bodega` (Jefe de Bodega, ver
     * `BusinessRoleUserSeeder`). No usa el `user_roles` legado (a diferencia de
     * `EvaluateNotificationRulesListener::userIdsByRole()`) porque `lider_bodega` es un role_key
     * del catálogo nuevo (ADR-006), no un valor del enum viejo.
     */
    private function notifyBodegaLeadOfUnassignedOrder(Order $order, int $sellerId): void
    {
        $roleId = Role::where('key', self::BODEGA_LEAD_ROLE_KEY)->value('id');

        /** @var list<int> $audience */
        $audience = $roleId !== null
            ? User::where('role_id', $roleId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        if ($audience === []) {
            Log::warning('bodega.order.no-bodega-lead-to-notify', [
                'order_id' => $order->id,
                'role_key' => self::BODEGA_LEAD_ROLE_KEY,
            ]);

            return;
        }

        $seller     = User::find($sellerId);
        $sellerName = $seller !== null ? "{$seller->first_name} {$seller->last_name}" : "vendedor #{$sellerId}";

        $this->notifications->send(
            audience: $audience,
            content: [
                'subject' => "Pedido {$order->order_number} sin asistente asignado",
                'body'    => "El pedido {$order->order_number} (vendedor: {$sellerName}) se creó en Bodega "
                    .'sin un Asistente de Bodega asignado — falta el mapeo vendedor->asistente para este '
                    .'vendedor (WarehouseAssistantMapping). Requiere asignación manual.',
            ],
            channels: [NotificationSend::CHANNEL_IN_APP, NotificationSend::CHANNEL_EMAIL],
            source: NotificationSend::SOURCE_BODEGA_ORDER_UNASSIGNED,
            sourceRef: "order:{$order->id}",
        );
    }

    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
