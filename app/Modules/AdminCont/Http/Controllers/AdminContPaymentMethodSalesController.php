<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Http\Resources\AdminCont\PaymentMethodSalesPendienteResource;
use App\Http\Resources\AdminCont\PaymentMethodSalesRowResource;
use App\Modules\AdminCont\Services\PaymentMethodSalesExportService;
use App\Modules\AdminCont\Services\PaymentMethodSalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 23 Grupo 3 (SCRUM-665→669, REQ-588→592) — "Ventas por medio de pago" (4M4). Controller
 * fino, delega todo a `PaymentMethodSalesReportService`.
 */
class AdminContPaymentMethodSalesController
{
    public function __construct(
        private readonly PaymentMethodSalesReportService $report,
        private readonly PaymentMethodSalesExportService $export,
    ) {}

    /**
     * GET /api/admin-contab/reports/ventas-medio-pago — `filas`/`pendientes` se envuelven en
     * Resources recién acá (capa HTTP), nunca en el service (que `PaymentMethodSalesExportService`
     * reusa con el array plano). Envolver en el controller es lo que le da a Scramble (generador
     * de OpenAPI/tipos TS) el shape completo de la fila, ver
     * `docs/architecture/typescript-types-from-backend.md`.
     */
    public function index(Request $request): JsonResponse
    {
        $report = $this->build($request);

        return response()->json([
            'resumen'    => $report['resumen'],
            'filas'      => PaymentMethodSalesRowResource::collection($report['filas']),
            'pendientes' => PaymentMethodSalesPendienteResource::collection($report['pendientes']),
        ]);
    }

    /** GET /api/admin-contab/reports/ventas-medio-pago/excel */
    public function excel(Request $request): StreamedResponse|JsonResponse
    {
        $report = $this->build($request);

        // RN de REQ-592 — defensa server-side, aunque el frontend ya bloquee el botón sin datos.
        if ($report['filas'] === []) {
            return response()->json(['message' => 'No hay facturas para descargar con el filtro actual.'], 422);
        }

        $bytes    = $this->export->toExcel($report);
        $filename = 'Ventas_por_medio_pago_'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(
            fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array{resumen: array<string, mixed>, filas: list<array<string, mixed>>, pendientes: list<array<string, mixed>>} */
    private function build(Request $request): array
    {
        $raw            = $request->query('master_client_id');
        $masterClientId = ($raw === null || $raw === '' || $raw === 'todos') ? null : (int) $raw;

        return $this->report->report($masterClientId, $request->query('desde'), $request->query('hasta'));
    }
}
