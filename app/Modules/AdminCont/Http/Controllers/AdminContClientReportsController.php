<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Http\Resources\AdminCont\ClientCollectionPendienteResource;
use App\Http\Resources\AdminCont\ClientCollectionRowResource;
use App\Modules\AdminCont\Services\ClientCollectionReportExportService;
use App\Modules\AdminCont\Services\ClientCollectionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 23 Grupo 2 (SCRUM-651→660, REQ-574→583) — "Reporte mensual por cliente" (4M1) y "Mensual
 * por cliente — acumulado" (4M2). Controller fino, delega todo a `ClientCollectionReportService`
 * (agrupación 'dia'/'mes' es la única diferencia real entre los 2 tickets).
 *
 * @phpstan-import-type ClientCollectionRow from ClientCollectionReportService
 * @phpstan-import-type ClientCollectionResumen from ClientCollectionReportService
 * @phpstan-import-type ClientCollectionPendiente from ClientCollectionReportService
 */
class AdminContClientReportsController
{
    public function __construct(
        private readonly ClientCollectionReportService $reports,
        private readonly ClientCollectionReportExportService $export,
    ) {}

    /**
     * GET /api/admin-contab/reports/mensual-cliente — RN1 REQ-574: sin cliente seleccionado,
     * `{estado: 'sin_cliente'}` sin más campos, así que esta ruta y `acumulado()` de abajo NUNCA
     * declaran un tipo de retorno unión (`sin_cliente`|`ok`) en ningún método — cada una resuelve
     * su propia rama por separado y devuelve un `array{estado: 'ok', ...}` con shape plano, o el
     * array mínimo de `sin_cliente` — Scramble (generador de OpenAPI/tipos TS) no logra resolver
     * el shape interno de una lista cuando el tipo de retorno del método es una unión, aunque cada
     * rama individual sí resuelva perfecto por separado (confirmado empíricamente, ver
     * `docs/architecture/typescript-types-from-backend.md`).
     */
    public function mensual(Request $request): JsonResponse
    {
        $report = $this->buildReport($request, 'dia');
        if ($report['estado'] === 'sin_cliente') {
            return response()->json($report);
        }

        return response()->json($this->okPayload($report));
    }

    /** GET /api/admin-contab/reports/mensual-cliente-acumulado — ver nota de `mensual()` arriba. */
    public function acumulado(Request $request): JsonResponse
    {
        $report = $this->buildReport($request, 'mes');
        if ($report['estado'] === 'sin_cliente') {
            return response()->json($report);
        }

        return response()->json($this->okPayload($report));
    }

    /**
     * @param array{estado: 'ok', resumen: ClientCollectionResumen, filas: list<ClientCollectionRow>, totales: ClientCollectionRow, pendientes: list<ClientCollectionPendiente>} $report
     * @return array{estado: 'ok', resumen: ClientCollectionResumen, filas: AnonymousResourceCollection, totales: ClientCollectionRow, pendientes: AnonymousResourceCollection}
     */
    private function okPayload(array $report): array
    {
        return [
            'estado'     => 'ok',
            'resumen'    => $report['resumen'],
            'filas'      => ClientCollectionRowResource::collection($report['filas']),
            'totales'    => $report['totales'],
            'pendientes' => ClientCollectionPendienteResource::collection($report['pendientes']),
        ];
    }

    /** GET /api/admin-contab/reports/mensual-cliente/excel */
    public function mensualExcel(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'dia', 'Reporte_mensual_por_cliente');
    }

    /** GET /api/admin-contab/reports/mensual-cliente-acumulado/excel */
    public function acumuladoExcel(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'mes', 'Reporte_acumulado_por_cliente');
    }

    /** @return array{estado: 'sin_cliente'}|array{estado: 'ok', resumen: ClientCollectionResumen, filas: list<ClientCollectionRow>, totales: ClientCollectionRow, pendientes: list<ClientCollectionPendiente>} */
    private function buildReport(Request $request, string $agrupacion): array
    {
        [$masterClientId, $todos] = $this->clienteFromRequest($request);

        return $this->reports->report(
            $masterClientId,
            $todos,
            $agrupacion,
            $request->query('desde'),
            $request->query('hasta'),
        );
    }

    private function exportReport(Request $request, string $agrupacion, string $prefijoArchivo): StreamedResponse|JsonResponse
    {
        $report = $this->buildReport($request, $agrupacion);

        // RN1 REQ-578/583 — defensa server-side, aunque el frontend ya bloquee el botón sin datos.
        if ($report['estado'] !== 'ok' || $report['filas'] === []) {
            return response()->json(['message' => 'Selecciona un cliente con datos en el rango de fechas antes de descargar.'], 422);
        }

        $bytes    = $this->export->toExcel($report, $agrupacion);
        $filename = $prefijoArchivo.'_'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(
            fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array{0: int|null, 1: bool} [master_client_id, todos] */
    private function clienteFromRequest(Request $request): array
    {
        $raw = $request->query('master_client_id');
        if ($raw === null || $raw === '') {
            return [null, false];
        }
        if ($raw === 'todos') {
            return [null, true];
        }

        return [(int) $raw, false];
    }
}
