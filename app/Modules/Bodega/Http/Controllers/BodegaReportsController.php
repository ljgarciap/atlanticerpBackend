<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Services\BodegaReportsService;
use App\Modules\Bodega\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-490→495 (REQ-420→425, Bloque "Reportes de Bodega") — 4 endpoints de solo lectura, uno por
 * tarjeta de la pantalla. `permission:bodega.read` en las 4 rutas — cada ticket dice "Visible
 * para todos los perfiles de Bodega" (mismo gate que Bodegas/Ver Inventario). El selector de
 * período (SCRUM-490) es puramente frontend — este controller solo resuelve el `period` que ya
 * viene en el query string, ver `ReportPeriod::resolve()`.
 */
final class BodegaReportsController extends Controller
{
    public function __construct(private readonly BodegaReportsService $reports) {}

    /** GET /api/bodega/reports/productivity — REQ-421. */
    public function productivity(Request $request): JsonResponse
    {
        $period = ReportPeriod::resolve($request->query('period'));
        $result = $this->reports->productivity($period['from'], $period['to']);

        return response()->json(['period' => $this->periodPayload($period), ...$result]);
    }

    /** GET /api/bodega/reports/adjustment-accuracy — REQ-422. */
    public function adjustmentAccuracy(Request $request): JsonResponse
    {
        $period = ReportPeriod::resolve($request->query('period'));
        $result = $this->reports->adjustmentAccuracy($period['from'], $period['to']);

        return response()->json(['period' => $this->periodPayload($period), ...$result]);
    }

    /** GET /api/bodega/reports/warehouse-capacity — REQ-423. Snapshot, ver docblock del service. */
    public function warehouseCapacity(Request $request): JsonResponse
    {
        $period = ReportPeriod::resolve($request->query('period'));
        $result = $this->reports->warehouseCapacity();

        return response()->json(['period' => $this->periodPayload($period), ...$result]);
    }

    /** GET /api/bodega/reports/inventory-summary — REQ-424. Snapshot, ver docblock del service. */
    public function inventorySummary(Request $request): JsonResponse
    {
        $period = ReportPeriod::resolve($request->query('period'));
        $result = $this->reports->inventorySummary();

        return response()->json(['period' => $this->periodPayload($period), ...$result]);
    }

    /**
     * @param array{key: string, from: CarbonImmutable, to: CarbonImmutable} $period
     * @return array{key: string, from: string, to: string}
     */
    private function periodPayload(array $period): array
    {
        return [
            'key'  => $period['key'],
            'from' => $period['from']->toDateString(),
            'to'   => $period['to']->toDateString(),
        ];
    }
}
