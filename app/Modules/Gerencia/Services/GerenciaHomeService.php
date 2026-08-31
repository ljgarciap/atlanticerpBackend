<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Services;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\GeneralCountRequest;
use App\Modules\Bodega\Models\Order as BodegaOrder;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Support\AprobacionGate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Datos de la pantalla Home de Gerencia (SCRUM-156).
 *
 * Secciones:
 *   - kpis        : Variación margen, CxC al día, Proyectos activos
 *   - facturacion : KPIs + chart mensual basado en AdminContInvoice
 *   - proyectos   : KPIs + chart mensual basado en PipelineCard (cotizaciones)
 *   - aprobaciones: [] — módulo de aprobaciones no construido aún
 *   - calendario  : SCRUM-164 — servido por endpoint propio (GET /gerencia/calendar vía
 *     OutlookCalendarController), no por este agregador — ver GerenciaPage.tsx.
 *   - salud_modulos: métricas transversales de cada módulo
 *   - agendas     : Bodega + Servicios (pendiente de fuente real, retorna [])
 */
final class GerenciaHomeService
{
    private const ACTIVE_PIPELINE_STAGES = ['lead', 'design', 'quote', 'proposal'];

    public function __construct(
        private readonly AprobacionGate $gate,
        private readonly InventoryKpiService $inventoryKpis,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  int|null  $userId  ID del usuario autenticado (para filtrar aprobaciones)
     * @return array<string, mixed>
     */
    public function home(array $filters, ?int $userId = null): array
    {
        $desde = isset($filters['desde'])
            ? CarbonImmutable::parse($filters['desde'])->startOfDay()
            : CarbonImmutable::now()->startOfMonth();

        $hasta = isset($filters['hasta'])
            ? CarbonImmutable::parse($filters['hasta'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $vendedorId = isset($filters['vendedor_id']) ? (int) $filters['vendedor_id'] : null;
        $clienteId = isset($filters['cliente_id']) ? (int) $filters['cliente_id'] : null;

        return [
            'kpis' => $this->kpis($desde, $hasta, $vendedorId, $clienteId),
            'facturacion' => $this->facturacion($desde, $hasta, $clienteId, $vendedorId),
            'proyectos' => $this->proyectos($desde, $hasta, $vendedorId, $clienteId),
            ...$this->pendingApprovalsSection($userId),
            'salud_modulos' => $this->saludModulos(),
            'agendas' => $this->agendas(),
        ];
    }

    /** @return array<string, mixed> */
    private function kpis(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?int $vendedorId,
        ?int $clienteId,
    ): array {
        // Montos facturado/cotizado (para contexto en la tarjeta)
        $facturado = AdminContInvoice::whereBetween('created_at', [$desde, $hasta])
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
            ->sum('monto');

        $cotizado = (float) PipelineCard::where('stage', 'approved')
            ->whereBetween('updated_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->sum('amount');

        // SCRUM-156 RN1: margen_real_pct — margen % de las cotizaciones que se facturaron
        // en el período (invoice.quote_id → Quote → QuoteItem.cost).
        $invoiceQuoteIds = array_values(AdminContInvoice::whereBetween('created_at', [$desde, $hasta])
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
            ->whereNotNull('quote_id')
            ->pluck('quote_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all());
        $margenRealPct = $this->calcMargenDeQuoteIds($invoiceQuoteIds);

        // SCRUM-156 RN1: margen_cotiz_pct — margen % de las cotizaciones aprobadas en el
        // período (PipelineCard.stage=approved → Quote confirmada → QuoteItem.cost).
        $approvedProjectIds = array_values(PipelineCard::where('stage', 'approved')
            ->whereBetween('updated_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->pluck('sales_project_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all());
        $margenCotizPct = $this->calcMargenDeProjectIds($approvedProjectIds);

        // SCRUM-156 RN1: variacion_pct = diferencia en puntos porcentuales (real − cotizado).
        $variacionPct = ($margenRealPct !== null && $margenCotizPct !== null)
            ? round($margenRealPct - $margenCotizPct, 2)
            : null;

        // CxC al día: snapshot en tiempo real (no filtrado por desde/hasta)
        $cxcQuery = AdminContInvoice::where('es_incobrable', false)
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId));

        $totalCxC = $cxcQuery->sum('monto');
        $vencidasCxC = (clone $cxcQuery)->where('created_at', '<', Carbon::now()->subDays(30))->sum('monto');
        // Facturas que vencerán en los próximos 7 días = creadas hace 23-30 días
        $porVencer7dCxC = (clone $cxcQuery)
            ->whereBetween('created_at', [Carbon::now()->subDays(30), Carbon::now()->subDays(23)])
            ->sum('monto');

        // Proyectos activos: tarjetas en etapas no terminadas
        $proyectosActivos = PipelineCard::whereIn('stage', self::ACTIVE_PIPELINE_STAGES)
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->count();

        return [
            'variacion_margen' => [
                'facturado' => (float) $facturado,
                'cotizado' => (float) $cotizado,
                'variacion_pct' => $variacionPct,
                'margen_real_pct' => $margenRealPct,
                'margen_cotiz_pct' => $margenCotizPct,
            ],
            'cxc_al_dia' => [
                'total' => (float) $totalCxC,
                'vencidas' => (float) $vencidasCxC,
                'por_vencer_7d' => (float) $porVencer7dCxC,
            ],
            'proyectos_activos' => $proyectosActivos,
        ];
    }

    /**
     * SCRUM-159 RN1 (2026-08-26): `AdminContInvoice` no tiene FK directa a vendedor, pero sí
     * `sales_project_id` — y `PipelineCard` (que sí tiene el vendedor real, `owner_id`) se
     * relaciona con ese mismo `sales_project_id` por convención de negocio 1:1 (sin constraint
     * `unique`, pero `PipelineService::create()` siempre crea `SalesProject`+`PipelineCard` juntos
     * en una transacción — ver docblock de `Quote::linkedPipelineCard()`). Con esto se resuelve el
     * filtro de vendedor sin tocar el schema. Facturas sin `sales_project_id`, o cuyo proyecto no
     * tiene `PipelineCard` vinculada, quedan excluidas al filtrar por vendedor — no hay a quién
     * atribuirlas, mismo criterio que "sin dato, no se cuenta" del resto del panel.
     *
     * @return array<string, mixed>
     */
    private function facturacion(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?int $clienteId,
        ?int $vendedorId,
    ): array {
        $query = AdminContInvoice::whereBetween('created_at', [$desde, $hasta])
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
            ->when($vendedorId, fn ($q) => $this->filtrarPorVendedor($q, (int) $vendedorId));

        $total = (float) $query->sum('monto');
        $count = $query->count();
        $meses = max(1, (int) $desde->diffInMonths($hasta) + 1);

        // `toBase()` — esta consulta agrega por mes con `selectRaw`/`groupBy`, no representa filas
        // reales de `AdminContInvoice` (la columna `mes` no existe en la tabla). Sin `toBase()`,
        // Eloquent hidrata cada fila como `AdminContInvoice` y PHPStan marca `$row->mes` como
        // propiedad inexistente — con `toBase()` el resultado son `stdClass` planos, igual de
        // válido en runtime, y sin ese falso positivo.
        $chartMensual = AdminContInvoice::whereBetween('created_at', [$desde, $hasta])
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
            ->when($vendedorId, fn ($q) => $this->filtrarPorVendedor($q, (int) $vendedorId))
            ->toBase()
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as mes, SUM(monto) as monto")
            ->groupBy('mes')
            ->orderBy('mes')
            ->get()
            ->map(fn (\stdClass $row): array => ['mes' => (string) $row->mes, 'monto' => (float) $row->monto])
            ->values()
            ->all();

        // Margen del año: cotizaciones de facturas emitidas en el año en curso
        $anioQuoteIds = array_values(AdminContInvoice::whereYear('created_at', $hasta->year)
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
            ->when($vendedorId, fn ($q) => $this->filtrarPorVendedor($q, (int) $vendedorId))
            ->whereNotNull('quote_id')
            ->pluck('quote_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all());

        // Margen del mes/período: cotizaciones de facturas emitidas en el rango filtrado
        $mesQuoteIds = array_values(AdminContInvoice::whereBetween('created_at', [$desde, $hasta])
            ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
            ->when($vendedorId, fn ($q) => $this->filtrarPorVendedor($q, (int) $vendedorId))
            ->whereNotNull('quote_id')
            ->pluck('quote_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all());

        return [
            'kpis' => [
                'facturado_anio' => (float) AdminContInvoice::whereYear('created_at', $hasta->year)
                    ->when($clienteId, fn ($q) => $q->where('master_client_id', $clienteId))
                    ->when($vendedorId, fn ($q) => $this->filtrarPorVendedor($q, (int) $vendedorId))
                    ->sum('monto'),
                'facturado_mes' => $total,
                'margen_anio_pct' => $this->calcMargenDeQuoteIds($anioQuoteIds),
                'margen_mes_pct' => $this->calcMargenDeQuoteIds($mesQuoteIds),
                'facturas_count' => $count,
            ],
            'chart_mensual' => $this->zeroFillMeses($desde, $hasta, $chartMensual),
        ];
    }

    /**
     * SCRUM-160 — Gerencia Test, comentario 2026-08-26: cuando el rango filtrado solo tiene datos
     * reales en UN mes, `chart_mensual` traía un único ítem — `BarChart` (frontend) lo estira a
     * 100% del ancho (flex-1 con un solo hijo) y se ve como un bloque grueso, no como una barra
     * dentro de un gráfico de barras. Se rellenan con monto 0 los meses del rango [desde, hasta]
     * que no tienen datos, para que el gráfico siempre muestre una barra por cada mes real del
     * rango — mismo criterio que, por coincidencia, ya se veía bien en Facturación cuando el rango
     * filtrado tenía datos en varios meses seguidos.
     *
     * @param  array<int, array{mes: string, monto: float}>  $rows
     * @return list<array{mes: string, monto: float}>
     */
    private function zeroFillMeses(CarbonImmutable $desde, CarbonImmutable $hasta, array $rows): array
    {
        $porMes = [];
        foreach ($rows as $row) {
            $porMes[$row['mes']] = $row['monto'];
        }

        $meses = [];
        $cursor = $desde->startOfMonth();
        $fin = $hasta->startOfMonth();
        while ($cursor->lessThanOrEqualTo($fin)) {
            $mes = $cursor->format('Y-m');
            $meses[] = ['mes' => $mes, 'monto' => $porMes[$mes] ?? 0.0];
            $cursor = $cursor->addMonth();
        }

        return $meses;
    }

    /**
     * @param  Builder<AdminContInvoice>  $query
     * @return Builder<AdminContInvoice>
     */
    private function filtrarPorVendedor(Builder $query, int $vendedorId): Builder
    {
        return $query->whereIn(
            'sales_project_id',
            PipelineCard::where('owner_id', $vendedorId)->pluck('sales_project_id'),
        );
    }

    /** @return array<string, mixed> */
    private function proyectos(
        CarbonImmutable $desde,
        CarbonImmutable $hasta,
        ?int $vendedorId,
        ?int $clienteId,
    ): array {
        $baseQuery = PipelineCard::whereBetween('created_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId));

        $aprobadas = PipelineCard::where('stage', 'approved')
            ->whereBetween('updated_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->count();
        $montoAprobado = (float) PipelineCard::where('stage', 'approved')
            ->whereBetween('updated_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->sum('amount');

        // Cerrados (approved) este año
        $cerradosAnio = (float) PipelineCard::where('stage', 'approved')
            ->whereYear('updated_at', $hasta->year)
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->sum('amount');
        $cerradosAnioCount = PipelineCard::where('stage', 'approved')
            ->whereYear('updated_at', $hasta->year)
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->count();

        // Cerrados este mes (del rango filtrado)
        $cerradosMesCount = $aprobadas;

        // `toBase()` — ver el mismo comentario en `facturacion()`: esta consulta agrega por mes,
        // no representa filas reales de `PipelineCard`.
        $chartMensual = PipelineCard::whereBetween('created_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->toBase()
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as mes, SUM(amount) as monto")
            ->groupBy('mes')
            ->orderBy('mes')
            ->get()
            ->map(fn (\stdClass $row): array => ['mes' => (string) $row->mes, 'monto' => (float) $row->monto])
            ->values()
            ->all();

        // Margen anio: cotizaciones de tarjetas cerradas (approved) en el año en curso
        $cerradosAnioProjectIds = array_values(PipelineCard::where('stage', 'approved')
            ->whereYear('updated_at', $hasta->year)
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->pluck('sales_project_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all());

        // Margen mes: cotizaciones de tarjetas cerradas en el rango filtrado
        $cerradosMesProjectIds = array_values(PipelineCard::where('stage', 'approved')
            ->whereBetween('updated_at', [$desde, $hasta])
            ->when($vendedorId, fn ($q) => $q->where('owner_id', $vendedorId))
            ->pluck('sales_project_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all());

        return [
            'kpis' => [
                'cerrados_anio' => $cerradosAnio,
                'cerrados_anio_count' => $cerradosAnioCount,
                'cerrados_mes' => (float) $montoAprobado,
                'cerrados_mes_count' => $cerradosMesCount,
                'margen_anio_pct' => $this->calcMargenDeProjectIds($cerradosAnioProjectIds),
                'margen_mes_pct' => $this->calcMargenDeProjectIds($cerradosMesProjectIds),
            ],
            'chart_mensual' => $this->zeroFillMeses($desde, $hasta, $chartMensual),
        ];
    }

    /**
     * Margen % ponderado de un conjunto de cotizaciones identificadas por sus IDs.
     *
     * Fórmula (SCRUM-156 RN1): (∑ingresos_con_costo − ∑costos) / ∑ingresos_con_costo × 100.
     * Solo considera ítems con cost != null y price_type != 'partner'.
     * Retorna null si ningún ítem tiene costo registrado.
     *
     * @param  list<int>  $quoteIds
     */
    private function calcMargenDeQuoteIds(array $quoteIds): ?float
    {
        if (empty($quoteIds)) {
            return null;
        }

        $quotes = Quote::whereIn('id', $quoteIds)
            ->with('parts.items')
            ->get();

        return $this->calcMargenDeQuotes($quotes);
    }

    /**
     * Igual que calcMargenDeQuoteIds pero busca por sales_project_id de las cotizaciones
     * confirmadas (tarjetas PipelineCard → cotización asociada al mismo proyecto).
     *
     * @param  list<int>  $projectIds
     */
    private function calcMargenDeProjectIds(array $projectIds): ?float
    {
        if (empty($projectIds)) {
            return null;
        }

        $quotes = Quote::whereIn('sales_project_id', $projectIds)
            ->whereNotNull('confirmed_at')
            ->with('parts.items')
            ->get();

        return $this->calcMargenDeQuotes($quotes);
    }

    /**
     * Calcula el margen % ponderado de una colección de cotizaciones ya cargadas con parts.items.
     *
     * @param  Collection<int, Quote>  $quotes
     */
    private function calcMargenDeQuotes(Collection $quotes): ?float
    {
        $sumRevenue = 0.0;
        $sumCost = 0.0;

        foreach ($quotes as $quote) {
            $priceType = $quote->price_type;

            foreach ($quote->parts as $part) {
                foreach ($part->items as $item) {
                    // Sin costo registrado → no hay margen que medir
                    if ($item->cost === null) {
                        continue;
                    }

                    // 'partner' tiene margen fijo por fórmula; excluirlo del agregado
                    // es el mismo criterio que usa costedPortfolioMarginBelowMinimum()
                    if (($item->price_type_override ?? $priceType) === 'partner') {
                        continue;
                    }

                    // Ingreso por línea: precio unitario × cantidad, descontando el descuento
                    // individual del ítem. El descuento global de la cotización (discount_mode /
                    // global_discount_percent) no se aplica aquí para no depender de PricingSettings
                    // (que requiere carga de tabla config en cada iteración) — la aproximación es
                    // suficientemente fiel para un KPI de gestión de alto nivel.
                    $itemDiscount = $item->discount_percent ?? 0.0;
                    $revenue = $item->unit_price * (1.0 - $itemDiscount / 100.0) * $item->quantity;
                    $cost = (float) $item->cost * $item->quantity;

                    $sumRevenue += $revenue;
                    $sumCost += $cost;
                }
            }
        }

        if ($sumRevenue <= 0.0) {
            return null;
        }

        return round(($sumRevenue - $sumCost) / $sumRevenue * 100.0, 2);
    }

    /**
     * Snapshot del mes actual — no responde a filtros de fecha (intencional).
     *
     * @return array<string, mixed>
     */
    private function saludModulos(): array
    {
        $inicioMes = CarbonImmutable::now()->startOfMonth();
        $finMes = CarbonImmutable::now()->endOfMonth();

        // ── Ventas & Diseño ───────────────────────────────────────────────────
        $vd = [
            'en_final_stage' => PipelineCard::where('stage', 'proposal')->count(),
            'estancados' => PipelineCard::whereIn('stage', self::ACTIVE_PIPELINE_STAGES)
                ->where('stage_changed_at', '<', Carbon::now()->subDays(PipelineCard::STAGNANT_THRESHOLD_DAYS))
                ->count(),
            'monto_cotizado_mes' => (float) PipelineCard::whereBetween('created_at', [$inicioMes, $finMes])
                ->sum('amount'),
            'monto_aprobado_mes' => (float) PipelineCard::where('stage', 'approved')
                ->whereBetween('updated_at', [$inicioMes, $finMes])
                ->sum('amount'),
            'pipeline_total' => (float) PipelineCard::whereIn('stage', self::ACTIVE_PIPELINE_STAGES)
                ->sum('amount'),
        ];

        // ── Admin & Contabilidad ──────────────────────────────────────────────
        // SCRUM-167 (REQ-105) RN1/RN3 — CxC Total y Cuentas al día (Daniela 2026-08-25).
        // CxP total eliminado. cxc_total = suma de facturas no incobrables (CxC pendiente de cobro).
        // cuentas_al_dia = CxC total menos lo vencido (> 30 días sin cobrar).
        $cxcBase = AdminContInvoice::where('es_incobrable', false);
        $cxcTotal = (float) $cxcBase->sum('monto');
        $cxcVenc = (float) (clone $cxcBase)
            ->where('created_at', '<', Carbon::now()->subDays(30))
            ->sum('monto');
        $ac = [
            'cxc_total' => $cxcTotal,
            'cuentas_al_dia' => (float) max(0, $cxcTotal - $cxcVenc),
        ];

        // ── Compras ───────────────────────────────────────────────────────────
        // SCRUM-168 (hallazgo Pre-QA v8): antes reimplementaba PurchaseOrder::isCritical() como
        // una query SQL cruda propia — dos definiciones independientes de la misma regla de
        // negocio divergían en los límites de día/hora (43 vs 44 confirmado en vivo el mismo día).
        // Mismo patrón que PurchaseOrderController@index chip=critical: isCritical() no es
        // traducible a un solo WHERE, se filtra en PHP sobre el set ya acotado (status != recibido
        // — status=recibido nunca es crítico, ver el cuerpo del método — así que el pre-filtro no
        // cambia el resultado, solo reduce lo que se carga).
        /** @var list<PurchaseOrder> $ordersNoRecibidas */
        $ordersNoRecibidas = PurchaseOrder::where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)->get()->all();
        $ordenesCriticas = count(array_filter($ordersNoRecibidas, fn (PurchaseOrder $o): bool => $o->isCritical()));

        $compras = [
            'ordenes_criticas' => $ordenesCriticas,
            // SCRUM-168 RN2 (Daniela 2026-08-25) — "Bajo stock sin ordenar": bajo_stock/sin_stock
            // sin ninguna orden de compra abierta cubriéndolo. Chip destino:
            // InventoryController@index ?chip=bajo_stock_sin_ordenar (InventoryKpiService::
            // isLowStockWithoutOpenOrder(), misma clasificación, nunca un cálculo paralelo).
            'bajo_stock_sin_ordenar' => $this->inventoryKpis->lowStockWithoutOpenOrderCount(),
            'en_transito' => PurchaseOrder::whereIn('status', [
                PurchaseOrder::STATUS_EN_TRANSITO,
                PurchaseOrder::STATUS_EN_ADUANA,
                PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            ])->count(),
            'proximas_a_llegar' => PurchaseOrder::where('status', PurchaseOrder::STATUS_EN_TRANSITO_LOCAL)->count(),
            'cantidad_pedidos' => PurchaseOrder::whereNotIn('status', [PurchaseOrder::STATUS_RECIBIDO])->count(),
        ];

        // ── Bodega ─────────────────────────────────────────────────────────────
        // SCRUM-169 (REQ-107) RN1-RN4 — Despachos urgentes/atrasados/a tiempo/completados hoy
        // (Daniela 2026-08-25). Eliminados: pedidos_activos, entregados_mes.
        // urgentes  = activos con committed_delivery_date <= hoy (vencidos o para hoy).
        // atrasados = activos con committed_delivery_date estrictamente pasado (< hoy).
        // a_tiempo  = entregados este mes donde la fecha de entrega real <= fecha comprometida.
        // completados_hoy = entregados hoy.
        $bodegaActiveStages = [
            BodegaOrder::STAGE_ASIGNADO,
            BodegaOrder::STAGE_PICKING_PENDIENTE,
            BodegaOrder::STAGE_EN_PICKING,
            BodegaOrder::STAGE_PACKING,
            BodegaOrder::STAGE_POR_DESPACHAR,
            BodegaOrder::STAGE_DESPACHADO,
        ];
        $hoyDate = Carbon::today();

        $bodega = [
            'despachos_urgentes' => BodegaOrder::whereIn('stage', $bodegaActiveStages)
                ->whereNotNull('committed_delivery_date')
                ->whereDate('committed_delivery_date', '<=', $hoyDate)
                ->count(),
            'despachos_atrasados' => BodegaOrder::whereIn('stage', $bodegaActiveStages)
                ->whereNotNull('committed_delivery_date')
                ->whereDate('committed_delivery_date', '<', $hoyDate)
                ->count(),
            'despachado_a_tiempo' => BodegaOrder::where('stage', BodegaOrder::STAGE_ENTREGADO)
                ->whereBetween('updated_at', [$inicioMes, $finMes])
                ->whereNotNull('committed_delivery_date')
                ->whereRaw('DATE(updated_at) <= committed_delivery_date')
                ->count(),
            'completados_hoy' => BodegaOrder::where('stage', BodegaOrder::STAGE_ENTREGADO)
                ->whereDate('updated_at', $hoyDate)
                ->count(),
        ];

        // ── Servicios ─────────────────────────────────────────────────────────
        // SCRUM-170 (REQ-108) RN1/RN3 — Sin responder y Completados este mes (Daniela 2026-08-25).
        // "Resuelto en 1ra visita" eliminado. cancelados_mes eliminado.
        // sin_responder   = tickets en estado REPORTED (sin atender aún).
        // completados_mes = tickets RESOLVED en el mes actual.
        $servicios = [
            'sin_responder' => Ticket::where('estado', Ticket::ESTADO_REPORTED)->count(),
            'completados_mes' => Ticket::where('estado', Ticket::ESTADO_RESOLVED)
                ->whereBetween('updated_at', [$inicioMes, $finMes])
                ->count(),
        ];

        return [
            'ventas_diseno' => $vd,
            'admin_contab' => $ac,
            'compras' => $compras,
            'bodega' => $bodega,
            'servicios' => $servicios,
        ];
    }

    /**
     * Agenda del día: pedidos de bodega y visitas de servicios programados para hoy.
     *
     * @return array<string, mixed>
     */
    private function agendas(): array
    {
        $hoy = Carbon::today();

        // Bodega: pedidos activos con entrega comprometida para hoy. `pipeline_cards` no tiene
        // columna `name` — el nombre del proyecto vive en `SalesProject.name` (bug real: el select
        // limitado `pipelineCard:id,name` rompía con "column does not exist" en cuanto hubiera un
        // pedido real con entrega comprometida para hoy, algo que las pruebas manuales no
        // dispararon porque Eloquent no ejecuta el eager-load si la colección padre está vacía).
        $bodegaItems = BodegaOrder::whereDate('committed_delivery_date', $hoy)
            ->whereNotIn('stage', [BodegaOrder::STAGE_ENTREGADO])
            ->with(['pipelineCard:id,sales_project_id', 'pipelineCard.salesProject:id,name'])
            ->orderBy('committed_delivery_date')
            ->get()
            ->map(fn (BodegaOrder $o): array => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'stage' => $o->stage,
                'customer' => $o->customer_name,
                'project' => $o->pipelineCard?->salesProject?->name,
                'address' => $o->delivery_address,
            ])
            ->values()
            ->all();

        // Servicios: tickets con visita programada para hoy
        $serviciosItems = Ticket::whereDate('scheduled_at', $hoy)
            ->whereIn('estado', [
                Ticket::ESTADO_SCHEDULED,
                Ticket::ESTADO_ON_SITE,
            ])
            ->with('internalTechnician:id,first_name,last_name')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Ticket $t): array => [
                'id' => $t->id,
                'numero' => $t->numero,
                'tipo' => $t->tipo,
                'estado' => $t->estado,
                'hora' => $t->scheduled_at?->format('H:i'),
                'hora_fin' => $t->scheduled_ends_at?->format('H:i'),
                'cliente' => $t->cliente,
                'direccion' => $t->direccion,
                'tecnico' => $t->internalTechnician
                    ? $t->internalTechnician->first_name.' '.$t->internalTechnician->last_name
                    : null,
            ])
            ->values()
            ->all();

        return [
            'bodega' => $bodegaItems,
            'servicios' => $serviciosItems,
        ];
    }

    /**
     * Bandeja unificada de aprobaciones pendientes (SCRUM-161/162/163).
     *
     * Retorna: aprobaciones (items), is_approver (bool), pending_total (int).
     *
     * Rediseño 2026-08-26 (hallazgo Pre-QA v6/v7, SCRUM-161 RN2): antes esto era un boolean único
     * ("¿el usuario es aprobador de ALGÚN tipo?") que exponía TODAS las secciones a cualquier
     * aprobador — un usuario con una `ReglaAprobacion` de un solo tipo veía y podía intentar
     * accionar los otros 4 tipos también. Ahora cada sección se arma solo si
     * `AprobacionGate::canAct($user, $tipo)` — mismo gate que usa `GerenciaAprobacionController`
     * para autorizar la acción real, así que lo que aparece en la bandeja es exactamente lo que el
     * usuario puede accionar (RN2/Escenario 2 del ticket).
     *
     * @return array{aprobaciones: list<array<string, mixed>>, is_approver: bool, pending_total: int}
     */
    private function pendingApprovalsSection(?int $userId): array
    {
        $user = $userId !== null ? User::find($userId) : null;
        if ($user === null) {
            return [
                'aprobaciones' => [],
                'is_approver' => false,
                'pending_total' => 0,
            ];
        }

        /** @var Collection<int, array<string, mixed>> $items */
        $items = collect();
        $totalCount = 0;

        // ── Compras: POs pendientes de aprobación ───────────────────────────
        if ($this->gate->canAct($user, 'purchase_order')) {
            $poQuery = PurchaseOrder::where('status', PurchaseOrder::STATUS_POR_APROBAR)
                ->where('requires_primary_approval', true)
                ->whereNull('approved_by');
            $totalCount += $poQuery->count();
            $pos = (clone $poQuery)->orderBy('created_at')->limit(20)->get();

            foreach ($pos as $po) {
                $items->push([
                    'id' => $po->id,
                    'type' => 'purchase_order',
                    'module' => 'compras',
                    'reference' => "PO-{$po->id}",
                    'description' => 'Orden de compra por '.number_format((float) $po->total_amount, 2),
                    'amount' => (float) ($po->total_amount ?? 0),
                    'created_at' => $po->created_at->toIso8601String(),
                    'can_approve' => true,
                    'action_url' => '/compras/ordenes',
                ]);
            }
        }

        // ── Bodega: Reubicaciones pendientes ─────────────────────────────────
        if ($this->gate->canAct($user, 'relocation')) {
            $relQuery = RelocationRequest::where('estado', 'pendiente');
            $totalCount += $relQuery->count();
            $relocations = (clone $relQuery)->orderBy('created_at')->limit(20)->get();

            foreach ($relocations as $rel) {
                $items->push([
                    'id' => $rel->id,
                    'type' => 'relocation',
                    'module' => 'bodega',
                    'reference' => "REU-{$rel->id}",
                    'description' => 'Solicitud de reubicación de inventario',
                    'amount' => null,
                    'created_at' => $rel->created_at->toIso8601String(),
                    'can_approve' => true,
                    'action_url' => '/bodega/inventario',
                ]);
            }
        }

        // ── Bodega: Líneas de ajuste pendientes ──────────────────────────────
        if ($this->gate->canAct($user, 'adjustment')) {
            $adjQuery = AdjustmentRequestLine::where('estado', 'Pendiente');
            $totalCount += $adjQuery->count();
            $adjustments = (clone $adjQuery)->orderBy('created_at')->limit(20)->get();

            foreach ($adjustments as $adj) {
                $items->push([
                    'id' => $adj->id,
                    'type' => 'adjustment',
                    'module' => 'bodega',
                    'reference' => "AJS-{$adj->id}",
                    'description' => 'Ajuste de inventario pendiente',
                    'amount' => null,
                    'created_at' => $adj->created_at->toIso8601String(),
                    'can_approve' => true,
                    'action_url' => '/bodega/solicitud-ajuste',
                ]);
            }
        }

        // ── Bodega: Conteos generales pendientes de aprobación ───────────────
        if ($this->gate->canAct($user, 'general_count')) {
            $cntQuery = GeneralCountRequest::where('estado', 'pendiente_aprobacion');
            $totalCount += $cntQuery->count();
            $counts = (clone $cntQuery)->orderBy('created_at')->limit(20)->get();

            foreach ($counts as $cnt) {
                $items->push([
                    'id' => $cnt->id,
                    'type' => 'general_count',
                    'module' => 'bodega',
                    'reference' => "CNT-{$cnt->id}",
                    'description' => 'Conteo general pendiente de aprobación',
                    'amount' => null,
                    'created_at' => $cnt->created_at->toIso8601String(),
                    'can_approve' => true,
                    'action_url' => '/bodega/inventario-general',
                ]);
            }
        }

        // ── Compras: Solicitudes Zona Libre pendientes ───────────────────────
        if ($this->gate->canAct($user, 'zona_libre')) {
            $zlQuery = BodegaZonaLibreRequest::where('status', 'pendiente');
            $totalCount += $zlQuery->count();
            $zonaLibre = (clone $zlQuery)->orderBy('created_at')->limit(20)->get();

            foreach ($zonaLibre as $zl) {
                $items->push([
                    'id' => $zl->id,
                    'type' => 'zona_libre',
                    'module' => 'compras',
                    'reference' => "ZL-{$zl->id}",
                    'description' => 'Solicitud Zona Libre pendiente',
                    'amount' => null,
                    'created_at' => $zl->created_at->toIso8601String(),
                    'can_approve' => true,
                    'action_url' => '/bodega/ordenes-zona-libre',
                ]);
            }
        }

        // is_approver: ¿tiene autoridad real sobre AL MENOS un tipo? (independiente de si hoy hay
        // 0 solicitudes pendientes de ese tipo — mismo criterio que la versión anterior).
        $isApprover = $items->isNotEmpty()
            || collect(['purchase_order', 'relocation', 'adjustment', 'general_count', 'zona_libre'])
                ->contains(fn (string $tipo): bool => $this->gate->canAct($user, $tipo));

        return [
            'aprobaciones' => array_values($items->sortBy('created_at')->all()),
            'is_approver' => $isApprover,
            'pending_total' => $totalCount,
        ];
    }
}
