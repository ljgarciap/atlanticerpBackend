<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\AdminCont\Services\CarteraCommissionService;
use App\Modules\AdminCont\Services\CashPositionService;
use App\Modules\AdminCont\Services\CommissionOrderService;
use App\Modules\AdminCont\Services\CreditNoteService;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\AdminCont\Support\ReportPeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 22 de Admin&Cont (SCRUM-643→647, REQ-566→570) — home de Reportes. Controller fino,
 * delega todo el cálculo a los servicios (`CarteraCommissionService`, `InvoiceService`,
 * `CashPositionService`) — mismo criterio que el resto del módulo.
 */
class AdminContReportsController
{
    public function __construct(
        private readonly CarteraCommissionService $carteraCommission,
        private readonly InvoiceService $invoices,
        private readonly CashPositionService $cashPosition,
        private readonly CommissionOrderService $internalCommissions,
        private readonly ArchitectCommissionService $externalCommissions,
        private readonly CreditNoteService $creditNotes,
    ) {}

    /** GET /api/admin-contab/reports/felix-commission — REQ-567, nunca depende del período. */
    public function felixCommission(): JsonResponse
    {
        return response()->json($this->carteraCommission->felixCommissionForCurrentMonth());
    }

    /** GET /api/admin-contab/reports/cartera — REQ-568, nunca depende del período. */
    public function cartera(): JsonResponse
    {
        return response()->json([
            'aging'      => $this->invoices->agingBuckets(),
            'cobrado_90' => $this->invoices->over90DaysCollectedForCurrentMonth(),
        ]);
    }

    /** GET /api/admin-contab/reports/ventas?periodo=hoy|3m|6m|anio — REQ-569. */
    public function ventas(Request $request): JsonResponse
    {
        $periodo = $this->periodoFromRequest($request);
        if ($periodo === null) {
            return response()->json(['message' => 'periodo debe ser hoy, 3m, 6m o anio.'], 422);
        }

        return response()->json($this->invoices->ventasSeries($periodo));
    }

    /** GET /api/admin-contab/reports/flujo-caja?periodo=hoy|3m|6m|anio — REQ-570. */
    public function flujoCaja(Request $request): JsonResponse
    {
        $periodo = $this->periodoFromRequest($request);
        if ($periodo === null) {
            return response()->json(['message' => 'periodo debe ser hoy, 3m, 6m o anio.'], 422);
        }

        // RN2 — saldo hoy/proyectado 30d nunca dependen del período elegido; misma fórmula que ya
        // usa ArqueoCajaPage.tsx en frontend (`header.saldo_disponible_hoy + projected.neto`),
        // armada acá server-side para que el frontend no tenga que replicarla.
        $header    = $this->cashPosition->header();
        $projected = $this->cashPosition->projected(30);

        return response()->json([
            'saldo_disponible_hoy'      => $header['saldo_disponible_hoy'],
            'saldo_proyectado_30_dias'  => round($header['saldo_disponible_hoy'] + $projected['neto'], 2),
        ] + $this->cashPosition->netFlowSeries($periodo));
    }

    /**
     * GET /api/admin-contab/reports/comisiones?periodo=hoy|3m|6m|anio — REQ-571. Suma las filas ya
     * calculadas por `CommissionOrderService::commissionsCollectedByDate()` (internas) y
     * `ArchitectCommissionService::commissionsPaidByDate()` (externas) — nunca recalcula el monto
     * de una comisión acá, solo agrupa lo que esos 2 servicios ya devuelven.
     */
    public function comisiones(Request $request): JsonResponse
    {
        $periodo = $this->periodoFromRequest($request);
        if ($periodo === null) {
            return response()->json(['message' => 'periodo debe ser hoy, 3m, 6m o anio.'], 422);
        }

        $internas = $this->internalCommissions->commissionsCollectedByDate();
        $externas = $this->externalCommissions->commissionsPaidByDate();

        if ($periodo === 'hoy') {
            $hoy = CarbonImmutable::now()->toDateString();

            return response()->json([
                'periodo' => 'hoy',
                'tipo'    => 'hoy',
                'hoy'     => [
                    'internas' => round((float) $internas->where('fecha', $hoy)->sum('monto'), 2),
                    'externas' => round((float) $externas->where('fecha', $hoy)->sum('monto'), 2),
                ],
            ]);
        }

        $meses = ReportPeriodResolver::meses($periodo);

        return response()->json([
            'periodo' => $periodo,
            'tipo'    => 'meses',
            'meses'   => array_map(function (array $m) use ($internas, $externas): array {
                $desde = $m['inicio']->toDateString();
                $hasta = $m['fin']->toDateString();

                return [
                    'mes'      => $m['mes'],
                    'anio'     => $m['anio'],
                    'internas' => round((float) $internas->filter(fn (array $r) => $r['fecha'] >= $desde && $r['fecha'] <= $hasta)->sum('monto'), 2),
                    'externas' => round((float) $externas->filter(fn (array $r) => $r['fecha'] >= $desde && $r['fecha'] <= $hasta)->sum('monto'), 2),
                ];
            }, $meses),
        ]);
    }

    /**
     * GET /api/admin-contab/reports/notas-credito — REQ-572, nunca depende del período (RN2).
     */
    public function notasCredito(): JsonResponse
    {
        return response()->json(['motivos' => $this->creditNotes->porMotivoMesActual()]);
    }

    private function periodoFromRequest(Request $request): ?string
    {
        $periodo = $request->query('periodo', 'hoy');
        if (! is_string($periodo) || ! in_array($periodo, ReportPeriodResolver::PERIODOS, true)) {
            return null;
        }

        return $periodo;
    }
}
