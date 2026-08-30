<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\OrdersSettings;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Shared\Http\Pagination;
use Illuminate\Support\Collection;

/**
 * SCRUM-703→710 (REQ-623→630, Epic CRM Batch F) — pantalla "Pedidos": cotizaciones cuyo proyecto
 * está en etapa Aprobado en Pipeline, comparando el margen cotizado (congelado en
 * `QuoteItem::cost` al momento de cotizar, REQ-624 RN2 — ya existía, ninguna migración nueva) con
 * el margen facturado real.
 *
 * Costo facturado (REQ-630): en vez de un campo denormalizado sincronizado por evento, se
 * RECALCULA en cada carga (RN4 de REQ-623) vía join con Compras — decisión de Luis 2026-07-31 de
 * construir la integración real en este batch en vez de dejarlo mock, al confirmarse que la
 * plomería ya existe: `PurchaseOrderLine.sales_project_id` (REQ-130/132/133, buscador real, no
 * texto libre) identifica a qué Pedido pertenece cada línea de compra, y
 * `PurchaseOrderReceiptLine.unit_cost` (con `confirmed_at` no nulo = Ingreso de Mercancía
 * confirmado, SCRUM-220→236) es el costo real una vez facturado. Se usa el costo BASE de la línea
 * (no `totalCost()` con flete/aduana prorrateados) para ser comparable con `QuoteItem::cost`, que
 * tampoco los incluye — ver memory del batch para el porqué.
 *
 * RN6 (REQ-630): se asume 1 factura por producto por Pedido — si `purchase_order_id`+
 * `catalog_product_id` resuelve más de una línea de recepción confirmada, se toma la primera.
 */
final class OrdersService
{
    private const COST_VARIANCE_EPSILON = 0.005; // REQ-626 RN2 — diferencia de costo (no de margen).
    private const MARGIN_NOISE_THRESHOLD_PTS = 0.05; // REQ-625 RN2 — ruido de redondeo por ítem.

    public function __construct(private readonly FuzzySearchService $fuzzySearch) {}

    /**
     * @param array{scope?: string, search?: string, estado?: string} $filters
     * @param array{per_page: int, page: int} $pagination SCRUM-754 — summary sigue calculándose
     *   sobre el alcance (Mías/Equipo) sin filtros de búsqueda/Estado (REQ-626, sin cambios); data
     *   trae solo la página pedida DESPUÉS de aplicar esos filtros.
     * @return array{
     *     summary: array<string, mixed>,
     *     fuzzy: bool,
     *     data: list<array<string, mixed>>,
     *     meta: array{total: int, per_page: int, current_page: int, last_page: int},
     * }
     */
    public function list(User $user, array $filters = [], array $pagination = ['per_page' => 20, 'page' => 1]): array
    {
        // REQ-629 RN4: Pedidos no tiene selector explícito Mías/Equipo — el frontend nunca manda
        // `scope`. Hallazgo CRÍTICO de Pre-QA (2026-07-31): defaultear a 'own' cuando no viene el
        // filtro dejaba a Líder/Gerencia/Mark viendo solo lo suyo, nunca el equipo, porque nadie
        // les manda 'team' explícito. Sin selector, el default correcto es pedir SIEMPRE 'team' —
        // `buildOrders()` ya baja a 'own' internamente para quien no tenga `canViewTeamV2`
        // (RN1/RN3), así que esto no abre alcance a nadie que no debiera tenerlo.
        $orders = $this->buildOrders($user, $filters['scope'] ?? 'team');

        // REQ-626: los KPI se calculan sobre el alcance (Mías/Equipo) pero NO sobre los filtros de
        // búsqueda/Estado activos — el mockup real (1F__Ventas_Disen_o_Pedidos.html) confirma que
        // el resumen no se re-renderiza al escribir en el buscador, a diferencia de Cotizaciones
        // (QuoteListService::summary(), que sí se recalcula con los filtros).
        $summary = $this->summary($orders);

        $rows = $orders;
        if (! empty($filters['estado'])) {
            $estado = $filters['estado'];
            $rows = $rows->filter(fn (array $o): bool => $o['estado'] === $estado)->values();
        }

        $fuzzy = false;
        if (! empty($filters['search'])) {
            [$filtered, $fuzzy] = $this->fuzzySearch->search(
                array_values($rows->all()),
                (string) $filters['search'],
                fn (array $o): string => $this->searchableText($o),
            );
            $rows = collect($filtered);
        }

        $paginator = Pagination::ofArray(array_values($rows->all()), $pagination['per_page'], $pagination['page']);

        return [
            'summary' => $summary,
            'fuzzy'   => $fuzzy,
            'data'    => array_values(array_map(fn (array $o) => $this->formatRow($o), $paginator->items())),
            'meta'    => Pagination::meta($paginator),
        ];
    }

    /** @return array<string, mixed>|null null si el usuario no tiene alcance sobre este pedido (REQ-629). */
    public function detail(User $user, int $quoteId): ?array
    {
        $orders = $this->buildOrders($user, 'team');
        $order  = $orders->firstWhere('quote_id', $quoteId);
        if ($order === null) {
            return null;
        }

        if (! $user->canViewTeamV2('ventas_diseno') && $order['card']->owner_id !== $user->id) {
            return null;
        }

        return $this->formatDetail($order);
    }

    /**
     * Construye la lista base de Pedidos (RN1 REQ-623: PipelineCard en etapa "approved" con
     * cotización generada, folio no nulo) ya con el alcance Mías/Equipo aplicado — cada elemento
     * trae el cálculo completo de margen para reusar en list()/detail() sin volver a consultar.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildOrders(User $user, string $scope): Collection
    {
        $cardsQuery = PipelineCard::query()
            ->where('stage', PipelineCard::RESTRICTED_EDIT_STAGE)
            ->with(['salesProject', 'masterClient', 'owner']);

        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');
        if ($scope !== 'team' || ! $canSeeTeam) {
            $cardsQuery->where('owner_id', $user->id);
        }

        $cards = $cardsQuery->get();
        if ($cards->isEmpty()) {
            return collect();
        }

        $projectIds = $cards->pluck('sales_project_id')->filter()->unique()->values();

        /** @var Collection<int, Quote> $quotes */
        $quotes = Quote::whereIn('sales_project_id', $projectIds)
            ->whereNotNull('folio')
            ->with(['salesProject', 'masterClient', 'owner', 'parts.items.catalogProduct'])
            ->orderByDesc('generated_at')
            ->get()
            ->unique('sales_project_id'); // 1 Pedido por proyecto — la cotización generada más reciente.

        $quotesByProject = $quotes->keyBy('sales_project_id');

        $invoicedCosts = $this->resolveInvoicedCosts(array_values($projectIds->all()));
        $threshold     = OrdersSettings::current()->margin_drop_threshold_pts;

        $orders = collect();
        foreach ($cards as $card) {
            $quote = $card->sales_project_id !== null ? $quotesByProject->get($card->sales_project_id) : null;
            if ($quote === null) {
                continue; // Aprobado en Pipeline pero sin cotización generada — no es un Pedido todavía.
            }

            $items = $quote->parts->flatMap(fn ($part) => $part->items);
            $costsForProject = $invoicedCosts[$quote->sales_project_id] ?? [];

            $orders->push($this->calculate($card, $quote, $items, $costsForProject, $threshold));
        }

        return $orders->values();
    }

    /**
     * @param Collection<int, QuoteItem> $items
     * @param array<int, float> $invoicedCostsByProduct catalog_product_id => costo unitario facturado
     * @return array<string, mixed>
     */
    private function calculate(PipelineCard $card, Quote $quote, Collection $items, array $invoicedCostsByProduct, float $threshold): array
    {
        $montoVenta = 0.0;
        $costoCotizadoTotal = 0.0;
        $costoFacturadoTotal = 0.0; // solo ítems ya facturados
        $costoMixtoTotal = 0.0;     // facturado donde existe, cotizado como estimado donde no
        $pendientes = 0;
        $conVariacionMargen = 0; // REQ-625 RN2 (pts de margen)
        $conVariacionCosto = 0;  // REQ-626 RN2 (costo crudo)
        $itemRows = [];

        foreach ($items as $item) {
            $qty   = $item->quantity;
            $price = $item->unit_price;
            $costoCotizado = $item->cost;

            $montoVenta += $price * $qty;
            $costoCotizadoTotal += ($costoCotizado ?? 0) * $qty;

            $costoFacturado = $item->catalog_product_id !== null
                ? ($invoicedCostsByProduct[$item->catalog_product_id] ?? null)
                : null;

            $margenCotizadoItem = $this->margin($price, $costoCotizado);
            $margenFacturadoItem = null;
            $diffPts = null;

            if ($costoFacturado === null) {
                $pendientes++;
                $costoMixtoTotal += ($costoCotizado ?? 0) * $qty;
            } else {
                $costoFacturadoTotal += $costoFacturado * $qty;
                $costoMixtoTotal += $costoFacturado * $qty;

                if ($costoCotizado !== null && abs($costoFacturado - $costoCotizado) > self::COST_VARIANCE_EPSILON) {
                    $conVariacionCosto++;
                }

                $margenFacturadoItem = $this->margin($price, $costoFacturado);
                if ($margenCotizadoItem !== null && $margenFacturadoItem !== null) {
                    $diffPts = round($margenFacturadoItem - $margenCotizadoItem, 2);
                    if (abs($diffPts) > self::MARGIN_NOISE_THRESHOLD_PTS) {
                        $conVariacionMargen++;
                    }
                }
            }

            $itemRows[] = [
                'reference'         => $item->reference,
                'brand'             => $item->catalogProduct?->brand,
                'description'       => $item->description,
                'quantity'          => $qty,
                'unit_price'        => $price,
                'quoted_cost'       => $costoCotizado,
                'quoted_margin'     => $margenCotizadoItem,
                'invoiced_cost'     => $costoFacturado,
                'invoiced_margin'   => $margenFacturadoItem,
                'margin_diff_pts'   => $diffPts,
                'pending_purchase'  => $costoFacturado === null,
            ];
        }

        $todosFacturados = $pendientes === 0 && $items->isNotEmpty();
        $margenCotizado  = $this->margin($montoVenta, $costoCotizadoTotal);
        $margenFacturado = $this->margin($montoVenta, $costoMixtoTotal);

        $estado = $this->classifyStatus($items->count(), $pendientes, $conVariacionMargen, $margenCotizado, $margenFacturado, $threshold);

        return [
            'card'               => $card,
            'quote'              => $quote,
            'quote_id'           => $quote->id,
            'items'              => $itemRows,
            'amount'             => round($montoVenta, 2),
            'quoted_margin'      => $margenCotizado,
            'invoiced_margin'    => $margenFacturado,
            'invoiced_partial'   => ! $todosFacturados,
            'all_invoiced'       => $todosFacturados,
            'pending_count'      => $pendientes,
            'item_count'         => $items->count(),
            'has_cost_variance'  => $conVariacionCosto > 0,
            'estado'             => $estado,
            'quoted_cost_total'    => round($costoCotizadoTotal, 2),
            'invoiced_cost_total'  => round($costoFacturadoTotal, 2),
        ];
    }

    /**
     * REQ-625: RN1 Pendiente de compra / RN2 Sin variación / RN3 Margen bajó / RN4 Margen mejoró.
     */
    private function classifyStatus(int $itemCount, int $pendientes, int $conVariacionMargen, ?float $margenCotizado, ?float $margenFacturado, float $threshold): string
    {
        if ($itemCount === 0 || $pendientes === $itemCount) {
            return 'pendiente';
        }

        if ($conVariacionMargen === 0) {
            return 'igual';
        }

        if ($margenCotizado === null || $margenFacturado === null) {
            return 'igual';
        }

        return ($margenCotizado - $margenFacturado) > $threshold ? 'bajo' : 'mejoro';
    }

    /** REQ-624 RN1: (precio − costo) ÷ precio × 100. Null si no hay costo o el precio es inválido. */
    private function margin(float $price, ?float $cost): ?float
    {
        if ($cost === null || $price <= 0) {
            return null;
        }

        return round(($price - $cost) / $price * 100, 2);
    }

    /**
     * REQ-630: mapa [sales_project_id][catalog_product_id] => costo unitario facturado real, a
     * partir de líneas de compra ya asociadas a un Pedido (`PurchaseOrderLine.sales_project_id`)
     * cuya recepción de mercancía ya fue confirmada (`PurchaseOrderReceiptLine.confirmed_at`).
     *
     * Hallazgo CRÍTICO de Pre-QA (2026-07-31): una misma orden de compra puede tener varias
     * `PurchaseOrderLine` del mismo `catalog_product_id` repartidas entre Pedidos DISTINTOS (ej.
     * 5 unidades para el Pedido A, 3 para el Pedido B, en la misma PO). `PurchaseOrderReceiptLine`
     * no se parte por Pedido — es una sola línea por producto por recepción, sin forma de saber
     * cuánto de lo recibido corresponde a cada Pedido. Atribuirle el costo/estado "facturado" a
     * ambos pedidos por igual generaba estados "Margen bajó" falsos (RN6 de REQ-630 solo cubre el
     * caso de 1 factura por producto POR PEDIDO, no el de un producto repartido entre pedidos).
     * Corrección conservadora: si el par (purchase_order_id, catalog_product_id) tiene líneas de
     * MÁS de un `sales_project_id` distinto — considerando TODAS las líneas de esa PO, no solo las
     * de los proyectos visibles para este usuario, porque la ambigüedad es real aunque el otro
     * Pedido no sea visible en este alcance —, no se resuelve costo para NINGUNO de los dos: quedan
     * "Pendiente de compra" en vez de arriesgar un número financiero incorrecto. Corregir la
     * atribución real (prorrateo o vínculo explícito por unidad) requiere una decisión de
     * diseño/negocio, pendiente de escalar a Analista/Arquitecto.
     *
     * @param list<int> $projectIds
     * @return array<int, array<int, float>>
     */
    private function resolveInvoicedCosts(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        /** @var Collection<int, PurchaseOrderLine> $poLines */
        $poLines = PurchaseOrderLine::whereIn('sales_project_id', $projectIds)
            ->whereNotNull('catalog_product_id')
            ->get(['id', 'purchase_order_id', 'catalog_product_id', 'sales_project_id']);

        if ($poLines->isEmpty()) {
            return [];
        }

        $purchaseOrderIds = $poLines->pluck('purchase_order_id')->unique()->values()->all();
        $productIds       = $poLines->pluck('catalog_product_id')->unique()->values()->all();

        // Todas las líneas (de cualquier Pedido, visible o no) de esas mismas PO+producto, para
        // detectar si el par está compartido entre más de un Pedido antes de resolver costo.
        /** @var Collection<int, PurchaseOrderLine> $allLinesForThoseOrders */
        $allLinesForThoseOrders = PurchaseOrderLine::whereIn('purchase_order_id', $purchaseOrderIds)
            ->whereIn('catalog_product_id', $productIds)
            ->whereNotNull('sales_project_id')
            ->get(['purchase_order_id', 'catalog_product_id', 'sales_project_id']);

        /** @var array<int, array<int, list<int>>> $projectIdsByOrderAndProduct */
        $projectIdsByOrderAndProduct = [];
        foreach ($allLinesForThoseOrders as $line) {
            $projectIdsByOrderAndProduct[$line->purchase_order_id] ??= [];
            $projectIdsByOrderAndProduct[$line->purchase_order_id][(int) $line->catalog_product_id] ??= [];
            $projectIdsByOrderAndProduct[$line->purchase_order_id][(int) $line->catalog_product_id][] = (int) $line->sales_project_id;
        }

        // Orden explícito por confirmed_at — antes dependía del orden físico de fila de Postgres
        // (no garantizado sin ORDER BY), hallazgo MEDIO de Pre-QA. "Primera confirmada gana": si
        // el mismo Pedido+producto tiene más de una PO con recepción confirmada, la más antigua
        // por `confirmed_at` es la autoritativa — resuelto más abajo comparando fechas, no por
        // orden de iteración (el bug real: 2 POs distintas para el mismo Pedido+producto quedan
        // en entradas SEPARADAS de `costByOrderAndProduct`, ninguna "pisa" a la otra ahí — la
        // elección final tiene que pasar por `confirmed_at`, no por qué PO se procesó primero).
        /** @var Collection<int, PurchaseOrderReceiptLine> $receiptLines */
        $receiptLines = PurchaseOrderReceiptLine::whereNotNull('confirmed_at')
            ->whereHas('receipt', fn ($q) => $q->whereIn('purchase_order_id', $purchaseOrderIds))
            ->with('receipt:id,purchase_order_id')
            ->orderBy('confirmed_at')
            ->get(['id', 'purchase_order_receipt_id', 'catalog_product_id', 'unit_cost', 'confirmed_at']);

        // [purchase_order_id][catalog_product_id] => {cost, confirmed_at} (1a confirmada gana
        // DENTRO de la misma PO — 2 recepciones de la misma PO para el mismo producto).
        /** @var array<int, array<int, array{cost: float, confirmed_at: \Carbon\Carbon}>> $costByOrderAndProduct */
        $costByOrderAndProduct = [];
        foreach ($receiptLines as $line) {
            $purchaseOrderId = $line->receipt?->purchase_order_id;
            if ($purchaseOrderId === null) {
                continue;
            }
            $costByOrderAndProduct[$purchaseOrderId] ??= [];
            $costByOrderAndProduct[$purchaseOrderId][(int) $line->catalog_product_id] ??= [
                'cost' => $line->unit_cost, 'confirmed_at' => $line->confirmed_at ?? now(),
            ];
        }

        // [sales_project_id][catalog_product_id] => {cost, confirmed_at} — entre varias PO que
        // resuelven al mismo Pedido+producto, se queda con la de `confirmed_at` más antiguo.
        /** @var array<int, array<int, array{cost: float, confirmed_at: \Carbon\Carbon}>> $candidates */
        $candidates = [];
        foreach ($poLines as $poLine) {
            $productId = (int) $poLine->catalog_product_id;

            $sharedAcross = $projectIdsByOrderAndProduct[$poLine->purchase_order_id][$productId] ?? [];
            if (count(array_unique($sharedAcross)) > 1) {
                continue; // ambiguo entre >1 Pedido — se deja "Pendiente de compra".
            }

            $candidate = $costByOrderAndProduct[$poLine->purchase_order_id][$productId] ?? null;
            if ($candidate === null || $poLine->sales_project_id === null) {
                continue;
            }
            $projectId = (int) $poLine->sales_project_id;
            $candidates[$projectId] ??= [];
            $existing = $candidates[$projectId][$productId] ?? null;
            if ($existing === null || $candidate['confirmed_at']->lt($existing['confirmed_at'])) {
                $candidates[$projectId][$productId] = $candidate;
            }
        }

        /** @var array<int, array<int, float>> $result */
        $result = [];
        foreach ($candidates as $projectId => $byProduct) {
            foreach ($byProduct as $productId => $candidate) {
                $result[$projectId][$productId] = $candidate['cost'];
            }
        }

        return $result;
    }

    /**
     * REQ-626: tarjetas de resumen — sobre el alcance ya aplicado, no sobre los filtros activos.
     *
     * @param Collection<int, array<string, mixed>> $orders
     * @return array<string, mixed>
     */
    private function summary(Collection $orders): array
    {
        $total = $orders->count();
        $conVariacion = $orders->filter(fn (array $o) => $o['has_cost_variance'])->count();

        $cotizados = $orders->pluck('quoted_margin')->filter(fn ($m) => $m !== null);
        $margenCotizadoPromedio = $cotizados->isNotEmpty() ? round((float) $cotizados->avg(), 2) : null;

        $facturadosCompletos = $orders->filter(fn (array $o) => $o['all_invoiced'] && $o['invoiced_margin'] !== null);
        $margenFacturadoPromedio = $facturadosCompletos->isNotEmpty()
            ? round((float) $facturadosCompletos->pluck('invoiced_margin')->avg(), 2)
            : null;

        return [
            'total_orders'              => $total,
            'with_margin_variance'      => $conVariacion,
            'avg_quoted_margin'         => $margenCotizadoPromedio,
            'avg_invoiced_margin'       => $margenFacturadoPromedio,
            'fully_invoiced_count'      => $facturadosCompletos->count(),
        ];
    }

    /** @param array<string, mixed> $order */
    private function searchableText(array $order): string
    {
        $quote = $order['quote'];

        return trim(implode(' ', array_filter([
            $quote->folio, $quote->masterClient?->name, $quote->salesProject?->name,
        ])));
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function formatRow(array $order): array
    {
        $quote = $order['quote'];
        $card  = $order['card'];

        return [
            'id'                => $order['quote_id'],
            'folio'             => $quote->folio,
            'master_client'     => $quote->masterClient !== null ? ['id' => $quote->masterClient->id, 'name' => $quote->masterClient->name] : null,
            'sales_project'     => $quote->salesProject !== null ? ['id' => $quote->salesProject->id, 'name' => $quote->salesProject->name] : null,
            'amount'            => $order['amount'],
            'approved_at'       => $card->stage_changed_at->toIso8601String(),
            'quoted_margin'     => $order['quoted_margin'],
            'invoiced_margin'   => $order['invoiced_margin'],
            'invoiced_partial'  => $order['invoiced_partial'],
            'estado'            => $order['estado'],
            'owner'             => ['id' => $quote->owner->id, 'name' => trim("{$quote->owner->first_name} {$quote->owner->last_name}")],
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function formatDetail(array $order): array
    {
        $row = $this->formatRow($order);
        $row['items'] = $order['items'];
        $row['quoted_cost_total']   = $order['quoted_cost_total'];
        $row['invoiced_cost_total'] = $order['invoiced_cost_total'];
        $row['pending_count']       = $order['pending_count'];
        $row['item_count']          = $order['item_count'];

        return $row;
    }
}
