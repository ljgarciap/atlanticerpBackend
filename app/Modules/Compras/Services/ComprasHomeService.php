<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Models\User;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Integrations\Outlook\Services\OutlookCalendarService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * REQ-111→118 (Inicio de Compras). Spec completa en los adjuntos de SCRUM-176
 * (`2__Requerimientos_Compras.xlsx`, hoja "Requerimientos Compras") — las descripciones de Jira
 * son un resumen, el Excel tiene las Reglas de Negocio completas.
 *
 * REQ-113 (Mi calendario / Outlook) — desbloqueado 2026-07-21 (acceso Azure AD confirmado, ver
 * OutlookCalendarService). El contador de reuniones del saludo (REQ-111) ahora lee el calendario
 * real del usuario logueado, ya no queda fijo en 0.
 *
 * REQ-114 (Pendientes) — el disparador "proveedor sin responder 5+ días" queda diferido: no existe
 * ningún campo que registre cuándo se envió una cotización/orden a un proveedor ni cuándo
 * respondió (`Provider` no tiene esa columna). Implementados los 2 disparadores que SÍ son
 * computables hoy con datos reales.
 */
final class ComprasHomeService
{
    private const REORDER_STATUSES_EXCLUYENTES = [
        PurchaseOrder::STATUS_RECIBIDO,
    ];

    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OutlookCalendarService $outlook,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        return [
            'resumen_mes'        => $this->resumenMes(),
            'pendientes'         => $this->pendientes(),
            'ordenes_criticas'   => $this->ordenesCriticas(),
            'estado_compras'     => $this->estadoCompras(),
            'por_reordenar'      => $this->porReordenar(),
            'events_today_count' => $this->eventsTodayCount($user),
        ];
    }

    /** REQ-113/REQ-111 — reuniones de hoy del usuario logueado (panel "Mi calendario"). */
    private function eventsTodayCount(User $user): int
    {
        if ($user->email === null || $user->email === '') {
            return 0;
        }

        $today  = CarbonImmutable::now();
        $result = $this->outlook->eventsForUsers([$user->email], $today->startOfDay(), $today->endOfDay());

        return count($result['events']);
    }

    /**
     * REQ-112. "Comprado en [mes]" = todas las órdenes CREADAS en el mes en curso, sin importar
     * su estado (RN explícita — no solo las recibidas). "Entregado a tiempo" se mide sobre las
     * RECIBIDAS dentro del mes (status_changed_at, cuando llegaron a Recibido), reusando
     * PurchaseOrder::isOnTime() (mismo check que Provider::ratingScore(), SCRUM-264, no
     * duplicado). "Bajo stock"/"Sin stock" reusan InventoryKpiService::kpis() byte-a-byte —
     * mismos números que Inventario, nunca un cálculo paralelo.
     *
     * @return array{comprado_mes: float, entregado_a_tiempo_percent: float|null, productos_retrasados: int, bajo_stock: int, sin_stock: int}
     */
    private function resumenMes(): array
    {
        $monthStart = now()->startOfMonth();

        $createdThisMonth = PurchaseOrder::where('created_at', '>=', $monthStart)->get();
        $compradoMes = round((float) $createdThisMonth->sum('total_amount'), 2);

        $receivedThisMonth = PurchaseOrder::where('status', PurchaseOrder::STATUS_RECIBIDO)
            ->where('status_changed_at', '>=', $monthStart)
            ->get();
        $entregadoATiempo = $this->onTimePercent($receivedThisMonth);

        // REQ-112: "Productos retrasados" navega al chip "Críticas" de Ver Órdenes -- para que el
        // número mostrado coincida EXACTO con lo que se ve al llegar (RN explícita), reusa la
        // misma definición completa de isCritical(), no solo la mitad "fecha vencida".
        $productosRetrasados = PurchaseOrder::where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->get()
            ->filter(fn (PurchaseOrder $o): bool => $o->isCritical())
            ->count();

        $inventoryKpis = $this->inventoryKpis->kpis();

        return [
            'comprado_mes'               => $compradoMes,
            'entregado_a_tiempo_percent' => $entregadoATiempo,
            'productos_retrasados'       => $productosRetrasados,
            'bajo_stock'                 => $inventoryKpis['low_stock'],
            'sin_stock'                  => $inventoryKpis['out_of_stock'],
        ];
    }

    /** @param Collection<int, PurchaseOrder> $received */
    private function onTimePercent(Collection $received): ?float
    {
        $withEstimate = $received->filter(fn (PurchaseOrder $o) => $o->isOnTime() !== null);
        if ($withEstimate->isEmpty()) {
            return null;
        }

        $onTime = $withEstimate->filter(fn (PurchaseOrder $o): bool => $o->isOnTime() === true);

        return round($onTime->count() / $withEstimate->count() * 100, 1);
    }

    /**
     * REQ-114 — 2 de los 3 disparadores documentados (ver docblock de la clase para el diferido).
     * Ambos ya son, en los hechos, los 2 casos de `PurchaseOrder::isCritical()` (por status): orden
     * sin aprobar 3+ días (status=por_aprobar) y llegada estimada vencida (cualquier otro status
     * no-Recibido) — se reusa la misma definición en vez de reimplementarla.
     *
     * @return list<array<string, mixed>>
     */
    private function pendientes(): array
    {
        $porAprobar = PurchaseOrder::with('provider')
            ->where('status', PurchaseOrder::STATUS_POR_APROBAR)
            ->get()
            ->filter(fn (PurchaseOrder $o): bool => $o->isCritical());

        $vencidas = PurchaseOrder::with('provider')
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->where('status', '!=', PurchaseOrder::STATUS_POR_APROBAR)
            ->get()
            ->filter(fn (PurchaseOrder $o): bool => $o->isCritical());

        $items = collect();
        foreach ($porAprobar as $order) {
            $items->push([
                'type'       => 'orden_sin_aprobar',
                'priority'   => 'media',
                'order_id'   => $order->id,
                'provider'   => $order->provider?->name,
                'amount'     => (float) $order->total_amount,
                // Carbon 3 devuelve float en diffInDays() — sin el cast, la UI mostraba
                // "3.7335738972800927 dias" (hallazgo QA SCRUM-178/179, 2026-07-20).
                'days'       => (int) $order->status_changed_at->diffInDays(now()),
                'suggestion' => 'Aprobar o rechazar la orden.',
            ]);
        }
        foreach ($vencidas as $order) {
            $items->push([
                'type'       => 'llegada_vencida',
                'priority'   => 'alta',
                'order_id'   => $order->id,
                'provider'   => $order->provider?->name,
                'amount'     => (float) $order->total_amount,
                'days'       => (int) ($order->estimated_arrival_date?->diffInDays(now()) ?? 0),
                'suggestion' => 'Dar seguimiento al proveedor por el atraso.',
            ]);
        }

        return array_values($items->sortByDesc(fn (array $i): int => $i['priority'] === 'alta' ? 1 : 0)->all());
    }

    /**
     * REQ-115 — misma definición de "crítica" que el chip de Ver Órdenes (RN1, no reimplementada).
     *
     * @return list<array<string, mixed>>
     */
    private function ordenesCriticas(): array
    {
        $criticas = PurchaseOrder::with('provider')
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->get()
            ->filter(fn (PurchaseOrder $o): bool => $o->isCritical());

        return array_values($criticas->map(fn (PurchaseOrder $o) => [
            'order_id' => $o->id,
            'motivo'   => $o->status === PurchaseOrder::STATUS_POR_APROBAR
                ? ((int) $o->status_changed_at->diffInDays(now())) . ' días sin aprobar'
                : ($o->estimated_arrival_date !== null
                    ? ((int) $o->estimated_arrival_date->diffInDays(now())) . ' días sobre el lead time'
                    : null),
            'provider' => $o->provider?->name,
            'amount'   => (float) $o->total_amount,
        ])->all());
    }

    /**
     * REQ-117 — 7 etapas (mismas que Ver Órdenes/Logística), cantidad y valor por etapa,
     * recalculado en vivo (no hay estado propio que sincronizar — se lee directo de purchase_orders).
     *
     * @return list<array{stage: string, count: int, amount: float}>
     */
    private function estadoCompras(): array
    {
        $stages = [
            PurchaseOrder::STATUS_POR_APROBAR,
            PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR,
            PurchaseOrder::STATUS_ORDENADO,
            PurchaseOrder::STATUS_EN_TRANSITO,
            PurchaseOrder::STATUS_EN_ADUANA,
            PurchaseOrder::STATUS_EN_TRANSITO_LOCAL,
            PurchaseOrder::STATUS_RECIBIDO,
        ];

        $byStatus = PurchaseOrder::query()
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return array_map(fn (string $stage) => [
            'stage'  => $stage,
            'count'  => (int) ($byStatus[$stage]->cnt ?? 0),
            'amount' => round((float) ($byStatus[$stage]->total ?? 0), 2),
        ], $stages);
    }

    /**
     * REQ-116 — doble condición: stock (Disponible) bajo el punto de reorden Y sin ninguna orden
     * activa (cualquier estado antes de Recibido) que ya lo cubra. Reusa el mismo cálculo de
     * "disponible" que Inventario (InventoryKpiService::porServirMap()) para no tener 2
     * definiciones de "stock actual" en pantallas distintas.
     *
     * @return list<array<string, mixed>>
     */
    private function porReordenar(): array
    {
        $candidates = CatalogProduct::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereNotNull('reorder_point')
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        /** @var list<int> $productIds */
        $productIds = array_values($candidates->pluck('id')->all());
        $porServirMap  = $this->inventoryKpis->porServirMap($productIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($productIds);

        $belowReorder = $candidates->filter(function (CatalogProduct $p) use ($porServirMap, $enExcluidaMap): bool {
            $disponible = $this->inventoryKpis->disponibleParaVenta($p, $porServirMap[$p->id] ?? 0, $enExcluidaMap[$p->id] ?? 0);

            return $disponible <= ($p->reorder_point ?? 0);
        });

        if ($belowReorder->isEmpty()) {
            return [];
        }

        // RN2: cualquier orden en un estado ANTES de Recibido que ya incluya el producto lo cubre
        // -- no importa cuánta cantidad, la sola presencia en una orden activa lo excluye.
        $coveredProductIds = PurchaseOrderLine::query()
            ->whereIn('catalog_product_id', $belowReorder->pluck('id')->all())
            ->whereHas('purchaseOrder', fn ($q) => $q->whereNotIn('status', self::REORDER_STATUSES_EXCLUYENTES))
            ->pluck('catalog_product_id')
            ->unique()
            ->all();

        return array_values($belowReorder
            ->reject(fn (CatalogProduct $p): bool => in_array($p->id, $coveredProductIds, true))
            ->map(function (CatalogProduct $p) use ($porServirMap, $enExcluidaMap) {
                $disponible = $this->inventoryKpis->disponibleParaVenta($p, $porServirMap[$p->id] ?? 0, $enExcluidaMap[$p->id] ?? 0);
                // Mismo cálculo ya usado en InventoryController::orderPrefill() -- no una fórmula paralela.
                $suggestedQuantity = max(1, ($p->reorder_point ?? 0) - ($p->stock_quantity ?? 0));

                return [
                    'catalog_product_id' => $p->id,
                    'reference'          => $p->reference,
                    'description'        => $p->name,
                    'stock_quantity'     => $p->stock_quantity,
                    'disponible'         => $disponible,
                    'reorder_point'      => $p->reorder_point,
                    'provider_id'        => $p->provider_id,
                    'provider_name'      => $p->provider?->name,
                    'suggested_quantity' => $suggestedQuantity,
                    'unit_cost'          => (float) $p->cost,
                ];
            })
            ->all());
    }
}
