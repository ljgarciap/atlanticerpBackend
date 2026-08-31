<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Support\BodegaRoles;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Oleada A / Batch A2 — tablero Kanban de Pedidos: listado con filtros/chips (REQ-309/
 * 310), estructura de 7 columnas con conteo (REQ-311), datos de tarjeta (REQ-312), panel de carga
 * por asistente (REQ-306), y el modal "Ver detalle" (REQ-332). Solo lectura — las mutaciones viven
 * en `PickingService`/`InventoryReviewService`/`DeliveryService`.
 */
final class OrderBoardService
{
    public function __construct(
        private readonly FamilyService $family,
        private readonly BodegaSettingsService $settings,
    ) {}

    /**
     * REQ-375/379/380/381 — listado completo (no paginado: el tablero Kanban necesita todas las
     * tarjetas activas de una vez para repartirlas en las 7 columnas, mismo criterio que cualquier
     * board Kanban de este sistema — no hay precedente de paginar un Kanban en AtlanticERP).
     *
     * @return array{cards: array<int, array<string, mixed>>, counts_by_stage: array<string, int>, total: int}
     */
    public function board(Request $request): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $request);
        $this->applyChip($query, $request);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Order> $orders */
        $orders = $query->orderBy('committed_delivery_date')->orderBy('created_at')->get();

        $counts = array_fill_keys(Order::STAGE_ORDER, 0);
        foreach ($orders as $order) {
            $counts[$order->stage] = ($counts[$order->stage] ?? 0) + 1;
        }

        // Senior Review 2026-07-23 (Blocker) — `eta_proveedor` (SCRUM-406) se calculaba con una
        // query de `PurchaseOrder` POR tarjeta "sin stock" dentro de `cardPayload()`, un N+1 real
        // sobre un board sin paginar. Se precalcula acá para TODO el board en 2 queries fijas
        // (`bulkSupplierEtaByProduct()`), mismo criterio de batching que
        // `WarehouseStockService::breakdownForProducts()`, y se le pasa el mapa ya resuelto a
        // cada `cardPayload()` en vez de que cada una vuelva a consultar por su cuenta.
        $awaitingStock = $orders->filter(fn (Order $o): bool => $o->isAwaitingStock());
        $awaitingStock->loadMissing('items');
        /** @var list<int> $productIds */
        $productIds = $awaitingStock->flatMap(fn (Order $o) => $o->items->pluck('catalog_product_id'))
            ->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
        $etaByProduct = $this->bulkSupplierEtaByProduct($productIds);

        // SCRUM-382 (REQ-312, corrección 2026-08-11) — "resumen de productos y cantidades" en
        // cada tarjeta: 1 sola query agregada para TODO el board (mismo criterio de batching que
        // `bulkSupplierEtaByProduct()` arriba), nunca un `count()`/`sum()` por tarjeta.
        /** @var list<int> $orderIdsForSummary */
        $orderIdsForSummary = array_values($orders->pluck('id')->map(fn ($id) => (int) $id)->all());
        $itemsSummary = $this->itemsSummaryByOrder($orderIdsForSummary);

        return [
            'cards' => $orders->map(fn (Order $o) => $this->cardPayload($o, $etaByProduct, $itemsSummary))->values()->all(),
            'counts_by_stage' => $counts,
            'total' => $orders->count(),
        ];
    }

    /**
     * REQ-306 — panel de carga por asistente.
     *
     * Corrección 2026-08-11 (Gerencia Test, SCRUM-376) — antes se derivaba la lista de "tarjetas"
     * agrupando los pedidos YA asignados (`groupBy('assistant_id')`), así que un asistente real de
     * Bodega sin ningún pedido asignado en este momento (ej. Asistente Bodega Test con 0) simplemente no
     * aparecía. Ahora se parte SIEMPRE del roster real de asistentes (`BodegaRoles::
     * ASISTENTE_BODEGA`, mismo criterio que `BodegaHomeService::team()`) y se les suma el conteo
     * de pedidos — un asistente con 0 pedidos sigue devolviendo su fila, con `orders_count: 0` y
     * `percent_of_total: 0`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function assistantLoad(): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = $this->baseQuery()->get();
        $total = $orders->count();

        /** @var Collection<int|string, Collection<int, Order>> $byAssistant */
        $byAssistant = $orders->whereNotNull('assistant_id')->groupBy('assistant_id');

        $assistantRoleIds = Role::where('key', BodegaRoles::ASISTENTE_BODEGA)->pluck('id');
        /** @var Collection<int, User> $assistants */
        $assistants = User::whereIn('role_id', $assistantRoleIds)->orderBy('first_name')->get();

        return $assistants->map(function (User $assistant) use ($byAssistant, $total): array {
            $count = $byAssistant->get($assistant->id)?->count() ?? 0;

            return [
                'assistant_id' => $assistant->id,
                'assistant_name' => $this->fullName($assistant),
                'orders_count' => $count,
                'percent_of_total' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    /**
     * REQ-332 — modal "Ver detalle".
     *
     * @return array<string, mixed>
     */
    public function detail(Order $order): array
    {
        $order->loadMissing([
            'items.catalogProduct', 'assistant', 'picker', 'courier',
            'pipelineCard.owner', 'pipelineCard.contacts', 'pipelineCard.salesProject.subClient.masterClient', 'documents',
        ]);

        $itemsSummary = $this->itemsSummaryByOrder([$order->id]);

        /** @var SubClient|null $subClient */
        $subClient = $order->pipelineCard?->salesProject?->subClient;

        // Lote 4 (SCRUM-395/402, 2026-08-17) — `customer_contact` es el string ya formateado
        // "Nombre (teléfono)" (`OrderService::formatContact()`), suficiente para el nombre pero sin
        // teléfono/correo utilizables por separado en los rediseños de Guía de Entrega/Ver detalle.
        // Se re-derivan del mismo contacto primario de la tarjeta del pipeline (misma fuente que usó
        // `OrderService::createFromApprovedQuote()` al armar el snapshot), no de un campo nuevo.
        $primaryContact = $order->pipelineCard?->contacts?->first();

        return array_merge($this->cardPayload($order, null, $itemsSummary), [
            // Frontend Dev (Batch A3, 2026-07-23) — `customer_contact`/`delivery_address` ya
            // existen en el modelo (poblados desde REQ-308) pero nunca se serializaban acá, dejando
            // el modal "Ver detalle" (REQ-332, que sí los pide) mostrando "—" siempre.
            'contacto_cliente' => $order->customer_contact,
            'contacto_telefono' => $primaryContact?->phone,
            'contacto_correo' => $primaryContact?->email,
            'direccion_entrega' => $order->delivery_address,
            // SCRUM-385 (REQ-315, corrección 2026-08-11) — Hoja de Picking pide "Cliente Master" y
            // "Subcliente" como campos INDEPENDIENTES, no el combinado `cliente` (que ya es
            // `customer_name` = `subCliente.business_name`, ver `OrderService::
            // createFromApprovedQuote()`). Se resuelven acá vía la cadena real
            // `pipelineCard -> salesProject -> subClient -> masterClient` — `null` si el pedido no
            // tiene `pipeline_card_id` (no debería pasar en el flujo normal, pero no es un caso
            // imposible en datos sembrados/legacy).
            'cliente_master' => $subClient?->masterClient?->name,
            'subcliente' => $subClient?->business_name,
            'items' => $order->items->map(function (OrderItem $i): array {
                /** @var CatalogProduct|null $product */
                $product = $i->catalogProduct;

                return [
                    'id' => $i->id,
                    'catalog_product_id' => $i->catalog_product_id,
                    'reference' => $product !== null ? $product->reference : $i->reference_snapshot,
                    // Frontend Dev (Batch A3) — la tabla de detalle (REQ-332) pide "Ref. fábrica"
                    // además de la pública; solo existe cuando el ítem tiene producto de catálogo
                    // real (`CatalogProduct.factory_reference`, sprint2/REQ-131), nunca para ítems
                    // personalizados (sin `catalog_product_id`).
                    'factory_reference' => $product?->factory_reference,
                    'description' => $product !== null ? $product->name : $i->description_snapshot,
                    'location' => $i->location_snapshot,
                    'qty_requested' => $i->qty_requested,
                    'qty_reserved' => $i->qty_reserved,
                    'qty_picked' => $i->qty_picked,
                    'qty_delivered' => $i->qty_delivered,
                    'shortage_reason' => $i->shortage_reason,
                ];
            })->values()->all(),
            // `eta_proveedor` ya viaja desde `cardPayload()` (SCRUM-406) — no se recalcula acá.
            // Pre-QA 2026-07-23 (CRÍTICO) — `received_by_name` (REQ-329/330): quien RECIBIÓ el
            // pedido, distinto de `repartidor` (quien lo entregó, ya expuesto arriba en cardPayload).
            'documents' => $order->documents->map(fn (OrderDocument $d) => [
                'id' => $d->id, 'tipo' => $d->tipo, 'created_at' => $d->created_at->toIso8601String(),
                'received_by_name' => $d->received_by_name,
            ])->values()->all(),
        ]);
    }

    /** @return Builder<Order> */
    private function baseQuery(): Builder
    {
        return Order::query()
            ->whereIn('stage', Order::STAGE_ORDER)
            ->with(['assistant', 'picker', 'courier', 'pipelineCard.owner', 'pipelineCard.salesProject']);
    }

    /** @param Builder<Order> $query */
    private function applyFilters(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('order_number', 'ilike', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%")
                    ->orWhereHas('pipelineCard.salesProject', fn (Builder $sp) => $sp->where('name', 'ilike', "%{$search}%"));
            });
        }

        $assistantId = $request->query('assistant_id');
        if (is_numeric($assistantId)) {
            $query->where('assistant_id', (int) $assistantId);
        }

        $sellerId = $request->query('seller_id');
        if (is_numeric($sellerId)) {
            $query->whereHas('pipelineCard', fn (Builder $pc) => $pc->where('owner_id', (int) $sellerId));
        }

        $cliente = $request->query('cliente');
        if (is_string($cliente) && trim($cliente) !== '') {
            $query->where('customer_name', $cliente);
        }
    }

    /** @param Builder<Order> $query */
    private function applyChip(Builder $query, Request $request): void
    {
        $chip = $request->query('chip');
        if (! is_string($chip)) {
            return;
        }

        $today = Carbon::today();

        match ($chip) {
            'urgentes' => $this->applyUrgentScope($query),
            'atrasados' => $query->whereNotNull('committed_delivery_date')
                ->where('committed_delivery_date', '<', $today->toDateString())
                ->whereNotIn('stage', [Order::STAGE_ENTREGADO]),
            'sin_stock' => $query->where('order_type', Order::ORDER_TYPE_FALTANTE)->where('stage', Order::STAGE_ASIGNADO),
            default => null,
        };
    }

    /**
     * REQ-310 RN1 — "hoy o mañana" (ventana configurable, ver `BodegaSettingsService`). Extraído
     * a público (Senior Review/Home batch, SCRUM-363→374) para que `BodegaHomeService` reuse
     * BYTE A BYTE la misma definición de "urgente" en los paneles "Despachos urgentes" (REQ-300)
     * y el chip del panel "Estado de pedidos" (REQ-302) — una sola fuente de verdad, nunca 2
     * definiciones de "urgente" que puedan desalinearse.
     *
     * @param  Builder<Order>  $query
     */
    public function applyUrgentScope(Builder $query): void
    {
        $today = Carbon::today();
        $urgente = $today->copy()->addDays($this->settings->urgenteWindowDays());

        $query->whereNotNull('committed_delivery_date')
            ->whereBetween('committed_delivery_date', [$today->toDateString(), $urgente->toDateString()])
            ->whereNotIn('stage', [Order::STAGE_ENTREGADO]);
    }

    /**
     * @param  array<int, string>|null  $etaByProduct  catalog_product_id => fecha ETA (batch de
     *                                                 `board()`), o null si se llama para un solo pedido (`detail()`).
     * @param  array<int, array{product_count: int, unit_count: int}>|null  $itemsSummary  order_id =>
     *                                                                                     resumen de productos/unidades (SCRUM-382), siempre pasado por el caller (`board()`/
     *                                                                                     `detail()`) — nunca null en la práctica, el default solo evita romper firmas viejas.
     * @return array<string, mixed>
     */
    private function cardPayload(Order $order, ?array $etaByProduct = null, ?array $itemsSummary = null): array
    {
        $today = Carbon::today();

        /** @var PipelineCard|null $pipelineCard */
        $pipelineCard = $order->pipelineCard;
        /** @var User|null $seller */
        $seller = $pipelineCard?->owner;
        /** @var SalesProject|null $salesProject */
        $salesProject = $pipelineCard?->salesProject;
        /** @var User|null $assistant */
        $assistant = $order->assistant;
        /** @var User|null $picker */
        $picker = $order->picker;
        /** @var User|null $courier */
        $courier = $order->courier;

        $committedDate = $order->committed_delivery_date;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'stage' => $order->stage,
            'proyecto' => $salesProject?->name,
            'cliente' => $order->customer_name,
            'vendedor' => $this->fullName($seller),
            'asistente' => $this->fullName($assistant),
            'picker' => $this->fullName($picker),
            'repartidor' => $this->fullName($courier),
            'fecha_entrega_comprometida' => $committedDate?->toDateString(),
            'is_atrasado' => $committedDate !== null
                && $committedDate->lt($today)
                && $order->stage !== Order::STAGE_ENTREGADO,
            'is_sin_stock' => $order->isAwaitingStock(),
            // SCRUM-406 (REQ-336) — solo se calcula (y paga el query extra) cuando el pedido está
            // realmente "sin stock": antes esto SOLO viajaba en `detail()`, obligando a abrir el
            // modal para ver la ETA de un pedido atascado en Asignado por falta de stock.
            'eta_proveedor' => $order->isAwaitingStock() ? $this->supplierEta($order, $etaByProduct) : null,
            'invoice_ready' => $order->invoice_ready,
            'family' => [
                'sequence_in_family' => $order->sequence_in_family,
                'total_in_family' => $order->total_in_family,
                'badge' => $this->family->completionBadge($order),
            ],
            // SCRUM-382 (REQ-312, corrección 2026-08-11) — "resumen de productos y cantidades"
            // ("X productos y Y unidades") pedido en la tarjeta.
            'items_summary' => $itemsSummary[$order->id] ?? ['product_count' => 0, 'unit_count' => 0],
        ];
    }

    /**
     * SCRUM-382 — 1 sola query agregada (`GROUP BY order_id`) para todos los `$orderIds` a la vez,
     * nunca un `count()`/`sum()` de `items` por tarjeta (mismo criterio de batching que
     * `bulkSupplierEtaByProduct()`).
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{product_count: int, unit_count: int}>
     */
    private function itemsSummaryByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        /** @var Collection<int, object{order_id: int, product_count: int, unit_count: int}> $rows */
        $rows = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, COUNT(*) as product_count, COALESCE(SUM(qty_requested), 0) as unit_count')
            ->groupBy('order_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->order_id] = [
                'product_count' => (int) $row->product_count,
                'unit_count' => (int) $row->unit_count,
            ];
        }

        return $map;
    }

    private function fullName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim("{$user->first_name} {$user->last_name}");

        return $name !== '' ? $name : null;
    }

    /**
     * REQ-336 RN1 — ETA jala de la orden de compra real asociada, no se captura manualmente.
     *
     * @param  array<int, string>|null  $etaByProduct  si viene (llamado desde `board()`, ya batcheado
     *                                                 con `bulkSupplierEtaByProduct()`), se usa ese mapa y no se dispara ninguna query
     *                                                 nueva. Si es `null` (llamado desde `detail()`, un solo pedido), cae al query directo
     *                                                 de siempre — una sola consulta para un solo pedido es aceptable, el problema real
     *                                                 (Senior Review 2026-07-23) era repetirla una vez por tarjeta en el board completo.
     */
    private function supplierEta(Order $order, ?array $etaByProduct = null): ?string
    {
        $order->loadMissing('items');
        /** @var list<int> $productIds */
        $productIds = $order->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
        if ($productIds === []) {
            return null;
        }

        if ($etaByProduct !== null) {
            /** @var list<string> $etas */
            $etas = array_values(array_filter(array_map(fn (int $id) => $etaByProduct[$id] ?? null, $productIds)));

            return $etas === [] ? null : min($etas);
        }

        $eta = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('catalog_product_id', $productIds))
            ->orderBy('estimated_arrival_date')
            ->value('estimated_arrival_date');

        return $eta !== null ? Carbon::parse((string) $eta)->toDateString() : null;
    }

    /**
     * Senior Review 2026-07-23 (Blocker) — misma ETA que `supplierEta()` calcula por pedido, pero
     * para TODOS los productos "sin stock" del board en 2 queries fijas (no 2 por tarjeta): una
     * para las `PurchaseOrder` candidatas + una para sus `lines` (eager load), nunca una consulta
     * nueva por pedido — mismo criterio de batching que `WarehouseStockService::breakdownForProducts()`.
     *
     * @param  list<int>  $productIds
     * @return array<int, string> catalog_product_id => fecha ETA más próxima entre sus órdenes de compra activas
     */
    private function bulkSupplierEtaByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var Collection<int, PurchaseOrder> $purchaseOrders */
        $purchaseOrders = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->whereHas('lines', fn (Builder $q) => $q->whereIn('catalog_product_id', $productIds))
            ->with(['lines' => fn ($q) => $q->whereIn('catalog_product_id', $productIds)])
            ->get(['id', 'status', 'estimated_arrival_date']);

        $etaByProduct = [];
        foreach ($purchaseOrders as $purchaseOrder) {
            if ($purchaseOrder->estimated_arrival_date === null) {
                continue;
            }
            $eta = $purchaseOrder->estimated_arrival_date->toDateString();
            foreach ($purchaseOrder->lines as $line) {
                $productId = (int) $line->catalog_product_id;
                if (! isset($etaByProduct[$productId]) || $eta < $etaByProduct[$productId]) {
                    $etaByProduct[$productId] = $eta;
                }
            }
        }

        return $etaByProduct;
    }
}
