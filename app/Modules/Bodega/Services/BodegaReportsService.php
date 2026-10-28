<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Support\BodegaRoles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Oleada A / Batch — Reportes de Bodega (SCRUM-490→495, REQ-420→425). 4 cálculos
 * independientes, cada uno detrás de su propio endpoint (ver `BodegaReportsController`).
 *
 * "productivity()"/"adjustmentAccuracy()" SÍ filtran por el rango de `ReportPeriod::resolve()`
 * (RN de REQ-421/422 hablan explícitamente de "en el período"). "warehouseCapacity()"/
 * "inventorySummary()" son snapshots del estado ACTUAL del inventario — ni REQ-423 ni REQ-424
 * mencionan una regla de negocio que dependa de un rango de fechas (a diferencia de los otros 2,
 * no hay "aprobadas/rechazadas en el período" ni "pedidos gestionados en el período" equivalente
 * acá), y REQ-424 exige explícitamente igualar los KPIs de Ver Inventario "para el mismo momento"
 * — un snapshot, no una serie histórica. El controller igual acepta/devuelve `period` en los 4
 * (RN1 de REQ-420: "el período aplica a los 4 reportes a la vez") para que el contrato sea
 * uniforme y el frontend no necesite tratar 2 tarjetas distinto — recalcular con el mismo
 * resultado determinista sigue siendo "recalcular".
 */
final class BodegaReportsService
{
    public function __construct(
        private readonly BodegaSettingsService $settings,
        private readonly BodegaInventoryKpiService $bodegaKpis,
    ) {}

    /**
     * REQ-421 — ranking de Asistentes de Bodega ACTIVOS (RN1: dinámico, nunca una lista fija) por
     * pedidos gestionados (asignados, `Order.assistant_id`) en el período. Escenario 2 — si NADIE
     * gestionó ningún pedido en el período, `has_data` es `false` y `data` viene vacío, para que
     * el frontend muestre el mensaje explicativo en vez de un ranking en blanco.
     *
     * @return array{has_data: bool, data: list<array{assistant_id: int, assistant_name: string, orders_count: int, percent_of_top: float}>}
     */
    public function productivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $assistantRoleId = Role::where('key', BodegaRoles::ASISTENTE_BODEGA)->value('id');

        /** @var Collection<int, User> $assistants */
        $assistants = User::where('role_id', $assistantRoleId)
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        if ($assistants->isEmpty()) {
            return ['has_data' => false, 'data' => []];
        }

        /** @var list<int> $assistantIds */
        $assistantIds = array_values($assistants->pluck('id')->map(fn ($id) => (int) $id)->all());

        /** @var Collection<int, int> $counts assistant_id => orders_count */
        $counts = Order::whereIn('assistant_id', $assistantIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('assistant_id, COUNT(*) as orders_count')
            ->groupBy('assistant_id')
            ->pluck('orders_count', 'assistant_id');

        $total = (int) $counts->sum();
        if ($total === 0) {
            return ['has_data' => false, 'data' => []];
        }

        $max = (int) $counts->max();

        $rows = $assistants
            ->map(function (User $assistant) use ($counts, $max): array {
                $count = (int) ($counts[$assistant->id] ?? 0);

                return [
                    'assistant_id'   => $assistant->id,
                    'assistant_name' => trim("{$assistant->first_name} {$assistant->last_name}"),
                    'orders_count'   => $count,
                    'percent_of_top' => $max > 0 ? round($count / $max * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('orders_count')
            ->values()
            ->all();

        return ['has_data' => true, 'data' => array_values($rows)];
    }

    /**
     * REQ-422 — RN1: los 3 KPIs (Aprobadas/Rechazadas/Pendientes) suman el total del período.
     * RN2: la tabla de motivos agrupa por el campo `motivo` REAL de cada línea (texto libre), no
     * una lista fija de motivos.
     *
     * @return array{
     *   total: int, aprobadas: int, rechazadas: int, pendientes: int,
     *   motivos: list<array{motivo: string, count: int}>,
     * }
     */
    public function adjustmentAccuracy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var Collection<int, AdjustmentRequestLine> $lines */
        $lines = AdjustmentRequestLine::whereBetween('created_at', [$from, $to])->get(['estado', 'motivo']);

        $motivos = $lines
            ->groupBy(fn (AdjustmentRequestLine $line): string => trim($line->motivo))
            ->map(fn (Collection $group, string $motivo): array => ['motivo' => $motivo, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
        $motivos = array_values($motivos);

        return [
            'total'      => $lines->count(),
            'aprobadas'  => $lines->where('estado', AdjustmentRequestLine::ESTADO_APROBADA)->count(),
            'rechazadas' => $lines->where('estado', AdjustmentRequestLine::ESTADO_RECHAZADA)->count(),
            'pendientes' => $lines->where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)->count(),
            'motivos'    => $motivos,
        ];
    }

    /**
     * REQ-423 — productos distintos + unidades totales almacenadas por cada una de las 7 bodegas,
     * más el aviso automático de concentración (RN1: dispara cuando una bodega concentra >= al
     * umbral configurable de `BodegaSettingsService::capacidadConcentracionPct()`, nunca
     * hardcodeado). Snapshot del estado actual, ver docblock de clase.
     *
     * @return array{
     *   warehouses: list<array{warehouse_id: int, name: string, products_count: int, units_count: int, percent_of_total: float}>,
     *   total_units: int,
     *   concentration_alert: array{warehouse_id: int, warehouse_name: string, percent: float, threshold_pct: int, suggested_targets: list<string>}|null,
     * }
     */
    public function warehouseCapacity(): array
    {
        /** @var Collection<int, Warehouse> $warehouses */
        $warehouses = Warehouse::activos()->orderBy('id')->get(['id', 'name']);

        /** @var Collection<int|string, Collection<int, ProductWarehouseStock>> $byWarehouse */
        $byWarehouse = ProductWarehouseStock::where('quantity', '>', 0)
            ->get(['warehouse_id', 'catalog_product_id', 'quantity'])
            ->groupBy('warehouse_id');

        $rows = $warehouses->map(function (Warehouse $warehouse) use ($byWarehouse): array {
            /** @var Collection<int, ProductWarehouseStock> $stockRows */
            $stockRows = $byWarehouse->get($warehouse->id, collect());

            return [
                'warehouse_id'   => $warehouse->id,
                'name'           => $warehouse->name,
                'products_count' => $stockRows->pluck('catalog_product_id')->unique()->count(),
                'units_count'    => (int) $stockRows->sum('quantity'),
            ];
        });

        $totalUnits = (int) $rows->sum('units_count');
        $thresholdPct = $this->settings->capacidadConcentracionPct();

        $rows = $rows->map(function (array $row) use ($totalUnits): array {
            $row['percent_of_total'] = $totalUnits > 0 ? round($row['units_count'] / $totalUnits * 100, 1) : 0.0;

            return $row;
        });

        return [
            'warehouses'          => array_values($rows->values()->all()),
            'total_units'         => $totalUnits,
            'concentration_alert' => $this->concentrationAlert($rows, $totalUnits, $thresholdPct),
        ];
    }

    /**
     * REQ-424 — RN1: reusa BYTE-A-BYTE `BodegaInventoryKpiService::kpis()` (el mismo servicio que
     * ya consume `BodegaInventoryController::index()` para "Ver Inventario"), para garantizar que
     * `total_products`/`in_attention`/`committed_units` coincidan EXACTO con esa pantalla en el
     * mismo momento (AC del ticket) — nunca un cálculo paralelo.
     *
     * @return array{total_products: int, in_attention: int, committed_units: int}
     */
    public function inventorySummary(): array
    {
        $kpis = $this->bodegaKpis->kpis();

        return [
            'total_products'  => $kpis['total_products'],
            'in_attention'    => $kpis['in_attention'],
            'committed_units' => $kpis['total_por_servir'],
        ];
    }

    /**
     * @param Collection<int, array{warehouse_id: int, name: string, products_count: int, units_count: int, percent_of_total: float}> $rows
     * @return array{warehouse_id: int, warehouse_name: string, percent: float, threshold_pct: int, suggested_targets: list<string>}|null
     */
    private function concentrationAlert(Collection $rows, int $totalUnits, int $thresholdPct): ?array
    {
        if ($totalUnits === 0) {
            return null;
        }

        /** @var array{warehouse_id: int, name: string, products_count: int, units_count: int, percent_of_total: float}|null $top */
        $top = $rows->sortByDesc('percent_of_total')->first();
        if ($top === null || $top['percent_of_total'] < $thresholdPct) {
            return null;
        }

        // REQ-423, Escenario 1 — sugiere redistribuir hacia las bodegas con MENOS ocupación
        // (excluyendo la concentrada), top 2 por unidades ascendente — mismo criterio del mockup
        // ("considera redistribuir... a las salas de exhibición, que tienen espacio disponible").
        $targets = $rows
            ->reject(fn (array $row): bool => $row['warehouse_id'] === $top['warehouse_id'])
            ->sortBy('units_count')
            ->take(2)
            ->pluck('name')
            ->values()
            ->all();

        return [
            'warehouse_id'      => $top['warehouse_id'],
            'warehouse_name'    => $top['name'],
            'percent'           => $top['percent_of_total'],
            'threshold_pct'     => $thresholdPct,
            'suggested_targets' => array_values($targets),
        ];
    }
}
