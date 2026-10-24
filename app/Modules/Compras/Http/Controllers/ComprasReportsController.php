<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderClaim;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\Compras\Support\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-262→268 (REQ-199→205) — Reportes avanzados de Compras.
 *
 * Los 6 paneles de acá comparten el contrato de período de SCRUM-262 (REQ-199): `period`
 * (month|quarter|year) + `anchor` (fecha opcional, default hoy) — ver ReportPeriod.
 *
 * REQ-202 ("Oportunidad de compra") — definición real tomada del Excel de requerimientos
 * (`2__Requerimientos_Compras.xlsx`, fila REQ-202 — el texto del ticket de Jira por sí solo era
 * ambiguo, usaba "A demanda"/"Tarde" en el criterio de aceptación sin definirlos): "A tiempo" =
 * disponible al crear la orden, "A demanda" = bajo stock, "Tarde" = sin stock. Aviso automático
 * si "A demanda" + "Tarde" COMBINADOS superan el 50% (no una sola categoría). Cada orden se
 * clasifica por el peor estado entre sus líneas (Tarde > A demanda > A tiempo) — ni el ticket ni
 * el Excel definen qué hacer con órdenes multi-producto en distintos estados; se optó por el
 * criterio más conservador (una orden cuenta como reactiva si CUALQUIERA de sus líneas lo fue).
 *
 * REQ-201 ("Calificación de proveedores") — actualizado 2026-07-18 (hallazgo de QA en SCRUM-264):
 * `Provider::ratingScore()` ya calcula un valor real con 2 de los 3 componentes de REQ-124
 * (entregas a tiempo + sin reclamos, renormalizados a 100%) — "entregas completas" sigue null
 * hasta que exista Ingreso de Mercancía, ver docblock del modelo. Este panel llama a los mismos
 * métodos que `Proveedores` (no una fórmula paralela), así que ambos siempre coinciden.
 */
final class ComprasReportsController extends Controller
{
    public function __construct(private readonly InventoryKpiService $inventoryKpis) {}

    /** REQ-200 — gráfico de barras, monto comprado por período, incluyendo el período en curso. */
    public function purchasedByPeriod(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $granularity = $this->resolveGranularity($request);
        if ($granularity === null) {
            return response()->json(['message' => 'Período inválido. Use month, quarter o year.'], 422);
        }

        $lookback = ['month' => 6, 'quarter' => 4, 'year' => 3][$granularity];
        $periods = ReportPeriod::lookback($granularity, $lookback);

        $buckets = array_map(function (ReportPeriod $p): array {
            $total = PurchaseOrder::whereBetween('created_at', [$p->start, $p->end])->sum('total_amount');

            return [
                'label' => $p->label(),
                'start' => $p->start->toDateString(),
                'end' => $p->end->toDateString(),
                'total_amount' => (float) $total,
                'is_current' => $p->isCurrent,
            ];
        }, $periods);

        return response()->json(['granularity' => $granularity, 'buckets' => $buckets]);
    }

    /** REQ-202 — ver nota de definición en el docblock de la clase (tomada del Excel real). */
    public function purchaseOpportunity(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $period = $this->resolvePeriodOrNull($request);
        if ($period === null) {
            return response()->json(['message' => 'Período inválido. Use month, quarter o year.'], 422);
        }

        $orders = PurchaseOrder::with('lines')
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get();

        $counts = [
            InventoryKpiService::STOCK_DISPONIBLE => 0,
            InventoryKpiService::STOCK_BAJO_STOCK => 0,
            InventoryKpiService::STOCK_SIN_STOCK => 0,
        ];
        $classified = 0;

        foreach ($orders as $order) {
            $states = $order->lines->pluck('stock_state_at_order')->filter()->all();
            if ($states === []) {
                // Órdenes de antes de este batch, sin el dato capturado — se excluyen del
                // cálculo en vez de asumirles un estado.
                continue;
            }

            $worst = match (true) {
                in_array(InventoryKpiService::STOCK_SIN_STOCK, $states, true) => InventoryKpiService::STOCK_SIN_STOCK,
                in_array(InventoryKpiService::STOCK_BAJO_STOCK, $states, true) => InventoryKpiService::STOCK_BAJO_STOCK,
                default => InventoryKpiService::STOCK_DISPONIBLE,
            };
            $counts[$worst]++;
            $classified++;
        }

        $percent = fn (int $n): ?float => $classified > 0 ? round($n / $classified * 100, 1) : null;
        $reactivePercent = $classified > 0
            ? round(($counts[InventoryKpiService::STOCK_BAJO_STOCK] + $counts[InventoryKpiService::STOCK_SIN_STOCK]) / $classified * 100, 1)
            : null;

        return response()->json([
            'period' => $this->formatPeriod($period),
            'classified_orders' => $classified,
            'a_tiempo_percent' => $percent($counts[InventoryKpiService::STOCK_DISPONIBLE]),
            'a_demanda_percent' => $percent($counts[InventoryKpiService::STOCK_BAJO_STOCK]),
            'tarde_percent' => $percent($counts[InventoryKpiService::STOCK_SIN_STOCK]),
            // REQ-202: aviso automático si "A demanda" + "Tarde" combinados superan el 50%.
            'show_warning' => $reactivePercent !== null && $reactivePercent > 50,
        ]);
    }

    /** REQ-201 — ver nota de alcance en el docblock de la clase (fórmula parcial de ratingScore()). */
    public function providerRating(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $period = $this->resolvePeriodOrNull($request);
        if ($period === null) {
            return response()->json(['message' => 'Período inválido. Use month, quarter o year.'], 422);
        }

        $providers = Provider::where('is_active', true)->get();

        $data = $providers->map(function (Provider $provider) use ($period): array {
            $purchased = PurchaseOrder::where('provider_id', $provider->id)
                ->whereBetween('created_at', [$period->start, $period->end])
                ->sum('total_amount');
            // Hallazgo de Senior Review: se scopeaba por created_at de la ORDEN, distinto al
            // panel de Reclamos (REQ-203), que scopea por created_at del RECLAMO — mismo período
            // seleccionado, dos definiciones distintas, los totales no coincidían entre paneles.
            $claimsCount = PurchaseOrderClaim::whereHas('purchaseOrder', fn ($q) => $q->where('provider_id', $provider->id))
                ->whereBetween('created_at', [$period->start, $period->end])
                ->count();

            return [
                'provider_id' => $provider->id,
                'provider_name' => $provider->name,
                'purchased_amount' => (float) $purchased,
                'claims_count' => $claimsCount,
                'rating' => $provider->ratingScore(),
                // SCRUM-264 — hallazgo de QA: el ticket pide estas 2 columnas explícitamente.
                // Mismos métodos que componen `rating`, no un cálculo paralelo (ver docblock de
                // Provider::ratingScore()). on_time_percent es all-time, no scopeado al período
                // del selector — mismo criterio que rating, que tampoco lo está.
                'on_time_percent' => $provider->onTimePercent(),
                'complete_percent' => $provider->completePercent(),
            ];
        })->sortByDesc('purchased_amount')->values();

        return response()->json([
            'period' => $this->formatPeriod($period),
            'data' => $data,
        ]);
    }

    /** REQ-203 — reclamos abiertos/resueltos, tasa sobre el total de órdenes, ranking por proveedor. */
    public function claims(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $period = $this->resolvePeriodOrNull($request);
        if ($period === null) {
            return response()->json(['message' => 'Período inválido. Use month, quarter o year.'], 422);
        }

        $claims = PurchaseOrderClaim::with('purchaseOrder.provider')
            ->whereBetween('created_at', [$period->start, $period->end])
            ->get();
        $totalOrders = PurchaseOrder::whereBetween('created_at', [$period->start, $period->end])->count();

        /** @var array<int, array{provider_id: int, provider_name: string|null, claims_count: int}> $countsByProvider */
        $countsByProvider = [];
        foreach ($claims as $claim) {
            $order = $claim->purchaseOrder;
            if ($order === null) {
                continue;
            }
            $countsByProvider[$order->provider_id] ??= [
                'provider_id' => $order->provider_id,
                'provider_name' => $order->provider?->name,
                'claims_count' => 0,
            ];
            $countsByProvider[$order->provider_id]['claims_count']++;
        }
        usort($countsByProvider, fn (array $a, array $b): int => $b['claims_count'] <=> $a['claims_count']);
        $ranking = $countsByProvider;

        return response()->json([
            'period' => $this->formatPeriod($period),
            'open_count' => $claims->where('status', PurchaseOrderClaim::STATUS_EN_REVISION)->count(),
            'resolved_count' => $claims->where('status', PurchaseOrderClaim::STATUS_RESUELTO)->count(),
            'claim_rate' => $totalOrders > 0 ? round($claims->count() / $totalOrders * 100, 1) : null,
            'provider_ranking' => $ranking,
        ]);
    }

    /** REQ-204 — mismos números que Inventario (InventoryKpiService), sin cálculo paralelo. */
    public function inventoryAttention(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->inventoryKpis->kpis());
    }

    /**
     * REQ-205 — "Liquidada" = misma definición que Agencias (PurchaseOrder::isLiquidated()).
     *
     * SCRUM-268 (hallazgo de Gerencia Test, 2026-08-11) — el panel se redefine para excluir del
     * universo las órdenes que TODAVÍA no llegaron a "Ordenado" (por_aprobar/pendiente_liquidar):
     * el subtítulo del mockup lo pide explícito ("basado en órdenes Ordenado / En tránsito /
     * Recibido"). Antes `total_imports` contaba TODA orden zona_libre del período sin importar la
     * etapa, diluyendo el % con órdenes que ni siquiera habían arrancado el flujo de importación.
     * "Por liquidar" = universo menos liquidadas (RN del ticket: Liquidados ÷ (Liquidados + Por
     * liquidar) × 100 — mismo denominador que el universo acá, ya que universo = liquidadas ∪ por
     * liquidar por construcción).
     *
     * ⚠️ AMBIGÜEDAD DE ALCANCE (sin resolver acá, ver comentario de Jira en SCRUM-268): "Liquidado"
     * sigue usando `PurchaseOrder::isLiquidated()` (llegó a "Recibido"), NO "llegó a En aduana"
     * pese a que el texto de Gerencia lo describe como equivalente ("Ya pasaron por aduana" /
     * nota al pie "equivalente al paso 'En aduana' completado"). Ese cambio de definición ya se
     * intentó y se revirtió a propósito el 2026-07-18 (ver docblock de `isLiquidated()`): un
     * proveedor `local` SALTA la etapa "en_aduana" en su secuencia de estados
     * (`statusSequence()`), así que una orden Zona Libre con proveedor local nunca podría contar
     * como "Liquidada" bajo esa definición, sin importar cuánto avance — quedaría "Por liquidar"
     * para siempre. Cambiarlo de nuevo sin que el Analista/Arquitecto revise ese caso reintroduce
     * un bug ya diagnosticado, así que se mantiene la definición actual (Recibido) hasta que se
     * decida explícitamente cómo tratar el caso de proveedor local.
     */
    public function liquidationImports(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $period = $this->resolvePeriodOrNull($request);
        if ($period === null) {
            return response()->json(['message' => 'Período inválido. Use month, quarter o year.'], 422);
        }

        $notStartedStatuses = [PurchaseOrder::STATUS_POR_APROBAR, PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR];

        $orders = PurchaseOrder::with('liquidationAgency')
            ->where('modality', PurchaseOrder::MODALITY_ZONA_LIBRE)
            ->whereBetween('created_at', [$period->start, $period->end])
            ->whereNotIn('status', $notStartedStatuses)
            ->get();
        $liquidated = $orders->filter(fn (PurchaseOrder $o): bool => $o->isLiquidated());
        $porLiquidar = $orders->reject(fn (PurchaseOrder $o): bool => $o->isLiquidated());

        /** @var array<int, array{agency_id: int, agency_name: string|null, liquidated_count: int}> $countsByAgency */
        $countsByAgency = [];
        foreach ($orders as $order) {
            if ($order->liquidation_agency_id === null) {
                continue;
            }
            $countsByAgency[$order->liquidation_agency_id] ??= [
                'agency_id' => $order->liquidation_agency_id,
                'agency_name' => $order->liquidationAgency?->name,
                'liquidated_count' => 0,
            ];
            if ($order->isLiquidated()) {
                $countsByAgency[$order->liquidation_agency_id]['liquidated_count']++;
            }
        }
        usort($countsByAgency, fn (array $a, array $b): int => $b['liquidated_count'] <=> $a['liquidated_count']);
        $ranking = $countsByAgency;

        return response()->json([
            'period' => $this->formatPeriod($period),
            'liquidated_count' => $liquidated->count(),
            'por_liquidar_count' => $porLiquidar->count(),
            'liquidated_percent' => $orders->count() > 0 ? round($liquidated->count() / $orders->count() * 100, 1) : null,
            'agency_ranking' => $ranking,
        ]);
    }

    private function resolveGranularity(Request $request): ?string
    {
        $granularity = (string) $request->query('period', 'month');

        return in_array($granularity, ReportPeriod::GRANULARITIES, true) ? $granularity : null;
    }

    private function resolvePeriodOrNull(Request $request): ?ReportPeriod
    {
        $granularity = $this->resolveGranularity($request);
        if ($granularity === null) {
            return null;
        }

        /** @var string|null $anchor */
        $anchor = $request->query('anchor');

        return ReportPeriod::resolve($granularity, $anchor);
    }

    /** @return array<string, mixed> */
    private function formatPeriod(ReportPeriod $period): array
    {
        return [
            'granularity' => $period->granularity,
            'label' => $period->label(),
            'start' => $period->start->toDateString(),
            'end' => $period->end->toDateString(),
            'is_current' => $period->isCurrent,
        ];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
