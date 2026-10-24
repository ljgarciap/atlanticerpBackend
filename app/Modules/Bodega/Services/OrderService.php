<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Events\OrderCreated;
use App\Modules\Bodega\Events\OrderSplit;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardContact;
use App\Modules\VentasDiseno\Models\PipelineCardProductDelivery;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307/308) — crea la tarjeta de Pedido en Bodega cuando una
 * cotización aprobada pasa a Pedido en Ventas & Diseño. Único punto de entrada de esta lógica: el
 * `App\Modules\VentasDiseno\Services\PipelineService::passToOrder()` (SCRUM-1128) lo invoca.
 */
final class OrderService
{
    /**
     * `role_key` real del catálogo de roles (`roles.key`, ver `BusinessRoleUserSeeder`) — Lider Bodega
     * Lider Bodega hoy. No hay un settings configurable dedicado (a diferencia de "Gerencia" en Compras,
     * `ComprasSettings.primary_approver_user_id`) porque acá la audiencia es un ROL, no una persona
     * puntual configurable — cualquiera con este rol recibe la alerta, sin necesidad de un campo
     * de configuración aparte (decisión 2026-07-22, revisión de Luis sobre el batch).
     */
    private const BODEGA_LEAD_ROLE_KEY = 'lider_bodega';

    public function __construct(
        private readonly OrderFolioService $folioService,
        private readonly NotificationService $notifications,
        private readonly FamilyService $family,
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

        // SCRUM-875 (REQ-319) — cuando la cotización se aprueba con "Fecha de entrega Parcial" y 2+
        // fechas, el pedido llega a Bodega dividido en una FAMILIA de tarjetas (una por fecha,
        // ordenadas de la más cercana a la más lejana). `delivery_type` != 'partial', o 'partial'
        // con 1 sola fecha → 1 tarjeta, comportamiento sin cambios.
        //
        // SCRUM-909 — completa lo de arriba: cada tarjeta de la familia lleva SOLO los productos
        // asignados a SU fecha (`PipelineCardProductDelivery`, armado en el modal de Pipeline antes
        // de aprobar) — ya no TODOS los ítems en cada una (esa era la decisión provisoria de Luis
        // 2026-09-09, "el reparto lo define Bodega en el picking", superada por este ticket).
        // `$productDateAssignments` vacío (nadie asignó nada — ej. tarjetas aprobadas antes de que
        // este ticket existiera, o el gate de `PipelineController::changeStage()` no corrió por
        // algún camino fuera del flujo normal) es el ÚNICO caso que cae de vuelta al
        // comportamiento viejo (todos los ítems en cada tarjeta, `$allowedQuoteItemIds = null` —
        // ver `createItemsFromQuote()`) — "no perder ningún producto" (REQ-308) pesa más que exigir
        // una asignación que, en ese caso, nunca se hizo.
        $partialDates = $card->delivery_type === 'partial'
            ? $card->deliveryDates->sortBy('date')->values()
            : collect();

        $productDateAssignments = ($partialDates->count() >= 2 && $quote !== null)
            ? PipelineCardProductDelivery::where('pipeline_card_id', $card->id)
                ->pluck('pipeline_card_delivery_date_id', 'quote_item_id')
                ->map(fn ($id) => (int) $id)
            : collect();

        // Ítems de la cotización sin ninguna fila de asignación (no debería pasar — el gate de
        // aprobación ya lo exige — pero si pasa, van a la tarjeta de la fecha más cercana en vez de
        // desaparecer silenciosamente de todo el pedido).
        $unassignedItemIds = [];
        if ($productDateAssignments->isNotEmpty() && $quote !== null) {
            $allItemIds = $quote->parts->flatMap(fn (QuotePart $part) => $part->items)->pluck('id')->all();
            $unassignedItemIds = array_values(array_diff($allItemIds, $productDateAssignments->keys()->all()));

            if ($unassignedItemIds !== []) {
                Log::warning('bodega.order.partial-products-without-date-assignment', [
                    'pipeline_card_id' => $card->id,
                    'quote_item_ids' => $unassignedItemIds,
                ]);
            }
        }

        return DB::transaction(function () use ($card, $quote, $mapping, $sellerId, $partialDates, $productDateAssignments, $unassignedItemIds): Order {
            $primaryContact = $card->contacts->first();

            $commonAttrs = [
                'order_number' => $this->folioService->next(),
                'pipeline_card_id' => $card->id,
                'order_type' => Order::ORDER_TYPE_PEDIDO,
                'stage' => Order::STAGE_ASIGNADO,
                'assistant_id' => $mapping?->assistant_user_id,
                'customer_name' => $card->subClient->business_name ?? $card->masterClient?->name,
                'customer_contact' => $this->formatContact($primaryContact),
                'delivery_address' => $card->subClient?->delivery_address,
            ];

            if ($partialDates->count() >= 2) {
                /** @var list<Order> $members */
                $members = [];
                foreach ($partialDates as $index => $deliveryDate) {
                    // null = sin ninguna asignación guardada → comportamiento viejo, todos los
                    // ítems en cada tarjeta (ver docblock de arriba).
                    /** @var list<int>|null $allowedQuoteItemIds */
                    $allowedQuoteItemIds = null;
                    if ($productDateAssignments->isNotEmpty()) {
                        $allowedQuoteItemIds = array_values($productDateAssignments
                            ->filter(fn (int $dateId): bool => $dateId === $deliveryDate->id)
                            ->keys()
                            ->all());

                        if ($index === 0 && $unassignedItemIds !== []) {
                            $allowedQuoteItemIds = array_values(array_unique([...$allowedQuoteItemIds, ...$unassignedItemIds]));
                        }
                    }

                    $members[] = $this->createOrderRow([
                        ...$commonAttrs,
                        'committed_delivery_date' => $deliveryDate->date,
                        // `family_id` se fija EN el create(), nunca en un update() posterior — el
                        // índice único parcial `orders_pipeline_card_root_uq` (WHERE family_id IS
                        // NULL) lo exige (ver docblock de FamilyService::attach()).
                        'family_id' => $index === 0 ? null : $members[0]->id,
                    ], $quote, $allowedQuoteItemIds);
                }

                $root = $members[0];
                $this->family->attach($root, $root); // resecuencia toda la familia (sequence/total)

                event(new OrderSplit(
                    parentOrderId: $root->id,
                    orderNumber: $root->order_number,
                    childOrderIds: array_map(static fn (Order $o): int => $o->id, array_slice($members, 1)),
                    tenant: $this->requiredTenantSlug(),
                ));

                $primaryOrder = $root;
            } else {
                $primaryOrder = $this->createOrderRow([
                    ...$commonAttrs,
                    'committed_delivery_date' => $card->deliveryDates->min('date'),
                ], $quote);
            }

            if ($mapping === null) {
                Log::warning('bodega.order.created-without-assistant-mapping', [
                    'order_id' => $primaryOrder->id,
                    'order_number' => $primaryOrder->order_number,
                    'seller_user_id' => $sellerId,
                ]);

                $this->notifyBodegaLeadOfUnassignedOrder($primaryOrder, $sellerId);
            }

            return $primaryOrder;
        });
    }

    /**
     * Pedido gemelo de ICG que Bodega todavía debe despachar (los de la hoja «Completo»: nada entregado en ICG): entra como un
     * pedido normal en «Asignado» — historial de etapa y `OrderCreated` para avisar al asistente — pero sin tarjeta del pipeline.
     * La reserva de stock ocurre, como siempre, al iniciar el picking. Los ítems ya vienen resueltos (`quote_item_id` de la
     * cotización gemela), no se derivan de una cotización aprobada.
     *
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $items
     */
    public function createIcgTwinForDispatch(array $attrs, array $items, \DateTimeInterface $fecha): Order
    {
        $order = Order::create($attrs);
        $order->forceFill(['created_at' => $fecha, 'updated_at' => $fecha])->saveQuietly();

        foreach ($items as $item) {
            $order->items()->create($item);
        }

        OrderStageHistory::create([
            'order_id' => $order->id, 'from_stage' => null, 'to_stage' => Order::STAGE_ASIGNADO, 'changed_by' => null, 'created_at' => $fecha,
        ]);

        event(new OrderCreated(
            orderId: $order->id,
            orderNumber: $order->order_number,
            assistantId: $order->assistant_id,
            tenant: $this->requiredTenantSlug(),
        ));

        return $order;
    }

    /**
     * Una tarjeta de Pedido: `Order` + ítems desde la cotización + fila inicial de historial de
     * etapa + evento `OrderCreated`. Extraído de `createFromApprovedQuote()` para reusarlo tanto en
     * el pedido de entrega única como en cada miembro de una familia de entregas parciales
     * (SCRUM-875).
     *
     * @param  array<string, mixed>  $attrs
     * @param  list<int>|null  $allowedQuoteItemIds  SCRUM-909 — si no es null, filtra los ítems de la
     *                                               cotización a solo estos `quote_item_id` (una fecha de la familia). `null` = sin filtrar,
     *                                               todos los ítems (entrega única, o entrega parcial sin ninguna asignación guardada).
     */
    private function createOrderRow(array $attrs, ?Quote $quote, ?array $allowedQuoteItemIds = null): Order
    {
        $order = Order::create($attrs);

        if ($quote !== null) {
            $this->createItemsFromQuote($order, $quote, $allowedQuoteItemIds);
        }

        OrderStageHistory::create([
            'order_id' => $order->id,
            'from_stage' => null,
            'to_stage' => Order::STAGE_ASIGNADO,
            'changed_by' => null, // creación automática del sistema, sin usuario detrás del clic
            'created_at' => now(),
        ]);

        event(new OrderCreated(
            orderId: $order->id,
            orderNumber: $order->order_number,
            assistantId: $order->assistant_id,
            tenant: $this->requiredTenantSlug(),
        ));

        return $order;
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
     *
     * @param  list<int>|null  $allowedQuoteItemIds  SCRUM-909 — ver docblock de `createOrderRow()`.
     */
    private function createItemsFromQuote(Order $order, Quote $quote, ?array $allowedQuoteItemIds = null): void
    {
        $items = $quote->parts->flatMap(fn (QuotePart $part) => $part->items);

        if ($allowedQuoteItemIds !== null) {
            $items = $items->filter(fn (QuoteItem $item): bool => in_array($item->id, $allowedQuoteItemIds, true));
        }

        $settings = PricingSettings::current();

        /** @var QuoteItem $item */
        foreach ($items as $item) {
            $order->items()->create([
                'catalog_product_id' => $item->catalog_product_id,
                // SCRUM-929 — vínculo explícito al QuoteItem de origen; llave de "cuánto se facturó
                // de cada ítem" (ver ADR-SCRUM929). Se propaga a toda tarjeta derivada del ítem.
                'quote_item_id' => $item->id,
                'reference_snapshot' => $item->catalogProduct->reference ?? $item->reference,
                'description_snapshot' => $item->catalogProduct->name ?? $item->description,
                'unit_price' => $item->unitPriceAfterDiscount(
                    $quote->discount_mode,
                    $quote->global_discount_percent,
                    $quote->price_type,
                    $settings,
                ),
                'qty_requested' => (int) round($item->quantity),
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

        $seller = User::find($sellerId);
        $sellerName = $seller !== null ? "{$seller->first_name} {$seller->last_name}" : "vendedor #{$sellerId}";

        $this->notifications->send(
            audience: $audience,
            content: [
                'subject' => "Pedido {$order->order_number} sin asistente asignado",
                'body' => "El pedido {$order->order_number} (vendedor: {$sellerName}) se creó en Bodega "
                    .'sin un Asistente de Bodega asignado — falta el mapeo vendedor->asistente para este '
                    .'vendedor (WarehouseAssistantMapping). Requiere asignación manual.',
                // SCRUM-1167 — el clic de la campana lleva al pedido.
                'cta_url' => '/bodega/pedidos/status?search='.rawurlencode((string) $order->order_number),
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
