<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SalesProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Oleada A / Batch A4 (REQ-337 a 343) — pantalla "Status de Pedidos": consulta de solo
 * lectura, compartida entre Bodega (ve todos los pedidos) y Ventas & Diseño (ve solo los propios,
 * ver `App\Modules\Bodega\Http\Controllers\OrderStatusController::resolveAccess()`), que muestra
 * el estado consolidado de cada pedido REAL — un pedido dividido en varias tarjetas por entrega
 * parcial (REQ-319, "familia") cuenta como UNA sola fila/consulta, nunca una por tarjeta.
 *
 * Servicio nuevo (no una extensión de `OrderBoardService`) a propósito: la responsabilidad acá es
 * distinta — "un pedido real consolidado a través de toda su familia", mientras que
 * `OrderBoardService` siempre trabaja a nivel de tarjeta individual para el tablero Kanban
 * (Bodega-only). Comparte `FamilyService` como única fuente de "quiénes son los miembros de una
 * familia", igual que `OrderBoardService`.
 */
final class OrderStatusService
{
    public function __construct(private readonly FamilyService $family) {}

    /**
     * REQ-338 — una fila por pedido real. Se consulta directo sobre las tarjetas RAÍZ
     * (`family_id IS NULL`): cada familia tiene exactamente una raíz y comparte `order_number`
     * (HU-335), así que iterar solo las raíces YA da "una fila por pedido real" sin necesidad de
     * agrupar en PHP.
     *
     * REQ-343 — lee `Order`/`pipelineCard` en vivo (sin caché intermedia); el único query
     * "derivado" (folio de cotización) se resuelve también en vivo en la misma request, solo
     * *batcheado* (REQ-337's escala) para no pagar 1 query por fila.
     *
     * @param int|null $restrictToOwnerId si no-null (usuario de Ventas & Diseño, REQ-342), limita
     *        a los pedidos cuya `PipelineCard.owner_id` sea este usuario. `null` = Bodega, ve todo.
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function list(Request $request, ?int $restrictToOwnerId): array
    {
        $query = Order::query()
            ->whereNull('family_id')
            ->with(['pipelineCard.owner', 'pipelineCard.salesProject']);

        if ($restrictToOwnerId !== null) {
            $query->whereHas('pipelineCard', fn (Builder $q) => $q->where('owner_id', $restrictToOwnerId));
        }

        $this->applySearch($query, $request);

        /** @var Collection<int, Order> $orders */
        $orders = $query->orderByDesc('created_at')->get();

        /** @var list<int> $projectIds */
        $projectIds = $orders
            ->map(fn (Order $o) => $o->pipelineCard?->sales_project_id)
            ->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
        $folioByProject = $this->bulkQuoteFolioBySalesProject($projectIds);

        return [
            'data'  => $orders->map(fn (Order $o) => $this->rowPayload($o, $folioByProject))->values()->all(),
            'total' => $orders->count(),
        ];
    }

    /** @param Builder<Order> $query */
    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return;
        }

        // SCRUM-1171 — `%` y `_` del texto buscado se escapan: antes funcionaban como comodín de LIKE (`search=%` listaba todo). El filtro por
        // dueño del vendedor siempre aplicaba, así que solo afectaba a lo propio, pero la búsqueda debe ser literal.
        $like = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('order_number', 'ilike', $like)
                ->orWhere('customer_name', 'ilike', $like)
                ->orWhereHas(
                    'pipelineCard.owner',
                    fn (Builder $u) => $u->where('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like),
                )
                ->orWhereHas(
                    'pipelineCard.salesProject.quotes',
                    fn (Builder $qq) => $qq->where('folio', 'ilike', $like),
                );
        });
    }

    /**
     * @param array<int, string|null> $folioByProject
     * @return array<string, mixed>
     */
    private function rowPayload(Order $order, array $folioByProject): array
    {
        /** @var PipelineCard|null $pipelineCard */
        $pipelineCard = $order->pipelineCard;
        $projectId    = $pipelineCard?->sales_project_id;

        return [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'quote_number' => $projectId !== null ? ($folioByProject[$projectId] ?? null) : null,
            'subcliente'   => $order->customer_name,
            'fecha_pedido' => $order->created_at->toDateString(),
            'vendedor'     => $this->fullName($pipelineCard?->owner),
        ];
    }

    /**
     * Resuelve la tarjeta RAÍZ de la familia a la que pertenece `$orderId`, sea o no esta misma la
     * raíz — el modal de detalle (REQ-339/340) y el documento (REQ-341) siempre consolidan a nivel
     * de familia completa, sin importar con qué miembro se haya abierto la pantalla.
     */
    public function resolveRoot(int $orderId): ?Order
    {
        $order = Order::find($orderId);
        if ($order === null) {
            return null;
        }

        if ($order->family_id === null) {
            return $order;
        }

        return Order::find($order->family_id) ?? $order;
    }

    /**
     * REQ-339/340 — payload del modal "Ver detalle" de Status de Pedidos, consolidado a través de
     * TODA la familia. Este mismo array es la fuente única de datos también para el documento PDF
     * (`OrderDocumentService::generateEstatusPedido()`, REQ-341 RN1: "los mismos datos que el
     * modal de detalle") — un solo lugar arma el payload, nunca se duplica la consolidación.
     *
     * @return array<string, mixed>
     */
    public function detailPayload(Order $root): array
    {
        $root->loadMissing(['pipelineCard.owner', 'pipelineCard.salesProject', 'pipelineCard.contacts']);

        /** @var PipelineCard|null $pipelineCard */
        $pipelineCard = $root->pipelineCard;
        $primaryContact = $pipelineCard?->contacts->first();

        $members = $this->family->members($root);
        $members->loadMissing('items.catalogProduct');

        return [
            'order_id'     => $root->id,
            'order_number' => $root->order_number,
            'quote_number' => $this->resolveQuoteFolio($pipelineCard?->sales_project_id),
            'proyecto'     => $pipelineCard?->salesProject?->name,
            // REQ-339 — "Diseñador" en el modal de detalle es el MISMO owner_id que "Vendedor" en
            // la tabla (REQ-338): no existen 2 personas distintas en el modelo de datos, ver
            // docblock de `PipelineCard.owner_id` — la etiqueta cambia según el contexto de la
            // pantalla, no el dato.
            'disenador'    => $this->fullName($pipelineCard?->owner),
            'cliente'      => $root->customer_name,
            'contacto'     => $primaryContact?->name,
            'telefono'     => $primaryContact?->phone,
            // Fecha de estatus (REQ-339): se calcula acá con `now()` solo para que el documento
            // PDF (que se renderiza server-side) tenga una fecha — el frontend puede usar su
            // propia fecha de cliente al abrir el modal en vez de depender de este campo
            // (decisión explícita del batch, ver reporte).
            'fecha_estatus' => now()->toDateString(),
            'items'        => $this->consolidatedItems($root, $members),
        ];
    }

    /**
     * REQ-340, corregido SCRUM-895 (2 vueltas) — por cada producto distinto de la familia:
     * `solicitada` se toma exclusivamente de los ítems de la tarjeta RAÍZ (1ra vuelta, ver más
     * abajo); `entregado` suma `qty_delivered` SOLO de las tarjetas miembro cuyo `stage` actual es
     * `Order::STAGE_ENTREGADO` (2da vuelta, rebote de Gerencia 2026-09-11).
     *
     * 2da vuelta — root cause real: `DeliveryService::registerDelivery()` setea `qty_delivered` en
     * la transición Packing → Por despachar (ver su docblock), NO al llegar a Entregado — el
     * nombre de la columna es la cantidad QUE SE VA A entregar, declarada al despachar, no una
     * confirmación de entrega física. Sumar `qty_delivered` de TODAS las tarjetas miembro sin
     * mirar su stage (comportamiento antes de este fix) contaba como "entregado" lo que todavía
     * estaba en Por despachar o Despachado — Pendiente quedaba en 0 antes de que la mercancía
     * físicamente llegara al cliente. Ahora solo las tarjetas que ya llegaron a Entregado
     * contribuyen a `entregado`; el resto (incluida la propia raíz si sigue en una etapa anterior)
     * queda reflejado en `pendiente` vía `solicitada - entregado`, tal como pide el ticket
     * ("Pendiente: tarjetas todavía en estados anteriores a Entregado").
     *
     * 1ra vuelta (bug real, pedido #PED-2026-0065/GE-SOCKET1): cuando Picking encuentra un
     * faltante parcial, `PickingService::createInventoryReviewCard()` crea una tarjeta hija con
     * `qty_requested` = REMANENTE (`short->qty_requested - short->qty_picked`) — pero la tarjeta
     * madre NUNCA reduce su propio `qty_requested` (sigue reflejando el pedido completo original,
     * ver RN1 de `PickingService::completePicking()`: solo `qty_picked` se ajusta). Mismo patrón
     * en `DeliveryService::createFaltanteCard()` para entregas parciales en Packing. Sumar
     * `qty_requested` de TODA la familia duplicaba ese remanente (ej. 20 solicitados con
     * picking parcial 10+10 → root conserva 20, hija pide 10 → suma ingenua = 30, dejando
     * `pendiente` en 10 aunque ya se entregó todo).
     *
     * La tarjeta RAÍZ es la única fuente correcta de "solicitada" porque es la única cuyo
     * `qty_requested` nunca se muta después de creada (ver docblock de `OrderItem` — ningún
     * service hace `update(['qty_requested' => ...])` sobre un ítem existente) y es "el pedido tal
     * como entró a Asignado en Bodega", exactamente la definición de "Solicitada" del ticket.
     *
     * Ítems personalizados (sin `catalog_product_id`, ver docblock de `OrderItem`) se agrupan por
     * su snapshot de referencia+descripción en vez de por id de catálogo, para no mezclar 2 ítems
     * personalizados distintos en una sola línea.
     *
     * @param Collection<int, Order> $members
     * @return array<int, array<string, mixed>>
     */
    private function consolidatedItems(Order $root, Collection $members): array
    {
        /** @var array<int, string> $stageByOrderId */
        $stageByOrderId = $members->pluck('stage', 'id')->all();

        /** @var Collection<int, OrderItem> $allItems */
        $allItems = $members->flatMap(fn (Order $o) => $o->items);

        $groups = $allItems->groupBy(function (OrderItem $item): string {
            return $item->catalog_product_id !== null
                ? "product:{$item->catalog_product_id}"
                : "custom:{$item->reference_snapshot}|{$item->description_snapshot}";
        });

        return $groups->map(function (Collection $items) use ($root, $stageByOrderId): array {
            /** @var OrderItem $first */
            $first = $items->first();
            /** @var CatalogProduct|null $product */
            $product = $first->catalogProduct;

            /** @var Collection<int, OrderItem> $rootItems */
            $rootItems = $items->where('order_id', $root->id);
            // Defensivo: el flujo real siempre origina cada producto en la raíz (ver docblock
            // arriba), pero si alguna vez no la tuviera, no dividimos "solicitada" en 0 sin
            // motivo — mejor la suma completa (comportamiento viejo) que un Pendiente absurdo.
            $solicitada = (int) ($rootItems->isNotEmpty() ? $rootItems->sum('qty_requested') : $items->sum('qty_requested'));
            $entregado  = (int) $items
                ->filter(fn (OrderItem $item): bool => ($stageByOrderId[$item->order_id] ?? null) === Order::STAGE_ENTREGADO)
                ->sum('qty_delivered');

            return [
                'catalog_product_id' => $first->catalog_product_id,
                'reference'          => $product->reference ?? $first->reference_snapshot,
                'factory_reference'  => $product?->factory_reference,
                'description'        => $product->name ?? $first->description_snapshot,
                'imagen'             => $product?->photo_url,
                'solicitada'         => $solicitada,
                'entregado'          => $entregado,
                'pendiente'          => max(0, $solicitada - $entregado),
            ];
        })->values()->all();
    }

    /**
     * REQ-338 RN1 — "N° cotización" no vive en `Order` (snapshot solo hereda ítems/cliente/
     * dirección, ver docblock de la migración `..._100002_...`); se resuelve igual que
     * `OrderService::resolveApprovedQuote()`: la cotización con folio más reciente del mismo
     * proyecto, o la más reciente sin folio si ninguna lo tiene todavía.
     */
    private function resolveQuoteFolio(?int $salesProjectId): ?string
    {
        if ($salesProjectId === null) {
            return null;
        }

        return Quote::where('sales_project_id', $salesProjectId)
                ->whereNotNull('folio')
                ->orderByDesc('generated_at')
                ->value('folio')
            ?? Quote::where('sales_project_id', $salesProjectId)
                ->orderByDesc('created_at')
                ->value('folio');
    }

    /**
     * Mismo criterio de batching que `OrderBoardService::bulkSupplierEtaByProduct()` (Senior
     * Review 2026-07-23): 1 sola query para TODOS los proyectos del listado, nunca 1 por fila —
     * el listado de Status de Pedidos (REQ-338) puede tener tantas filas como pedidos reales
     * existan, sin paginar (mismo criterio que el tablero Kanban).
     *
     * @param list<int> $salesProjectIds
     * @return array<int, string|null> sales_project_id => folio de la cotización más relevante
     */
    private function bulkQuoteFolioBySalesProject(array $salesProjectIds): array
    {
        if ($salesProjectIds === []) {
            return [];
        }

        /** @var Collection<int, Quote> $quotes */
        $quotes = Quote::whereIn('sales_project_id', $salesProjectIds)
            ->get(['id', 'sales_project_id', 'folio', 'generated_at', 'created_at']);

        $byProject = $quotes->groupBy('sales_project_id');

        $result = [];
        foreach ($salesProjectIds as $projectId) {
            /** @var Collection<int, Quote> $group */
            $group = $byProject->get($projectId, collect());

            /** @var Quote|null $withFolio */
            $withFolio = $group->whereNotNull('folio')->sortByDesc('generated_at')->first();

            $result[$projectId] = $withFolio->folio
                ?? $group->sortByDesc('created_at')->first()?->folio;
        }

        return $result;
    }

    private function fullName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $name = trim("{$user->first_name} {$user->last_name}");

        return $name !== '' ? $name : null;
    }
}
