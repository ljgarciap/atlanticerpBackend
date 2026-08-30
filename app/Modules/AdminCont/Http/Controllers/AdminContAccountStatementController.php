<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Exceptions\AccountStatementException;
use App\Modules\AdminCont\Services\AccountStatementExportService;
use App\Modules\AdminCont\Services\AccountStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 8 de Admin&Cont — Estado de Cuenta (SCRUM-529→533, REQ-452→456). REQ-452 (encabezado y
 * cierre de ventana) es puro frontend (`FocusedViewShell`, sin ruta propia acá). Batch 9
 * (SCRUM-534→538, REQ-457→461) extiende `show()`/`exportExcel()` para incluir saldo, bloque de
 * pago, nota automática y movimientos — mismo endpoint, respuesta superset, sin ruta nueva.
 */
class AdminContAccountStatementController
{
    public function __construct(
        private readonly AccountStatementService $accountStatement,
        private readonly AccountStatementExportService $export,
    ) {}

    /** GET /api/admin-contab/account-statement/clients?search= — REQ-453, buscador de cliente. */
    public function clients(Request $request): JsonResponse
    {
        $search = (string) $request->query('search', '');

        return response()->json(['data' => $this->accountStatement->searchClients($search)]);
    }

    /** GET /api/admin-contab/account-statement/projects?master_client_id= — REQ-453 RN1. */
    public function projects(Request $request): JsonResponse
    {
        $request->validate(['master_client_id' => ['required', 'integer']]);

        return response()->json(['data' => $this->accountStatement->projectsForClient(
            (int) $request->integer('master_client_id'),
        )]);
    }

    /** GET /api/admin-contab/account-statement?master_client_id=&sales_project_id=&desde=&hasta= — REQ-455/457→460. */
    public function show(Request $request): JsonResponse
    {
        // RN2 REQ-453 — buscar sin cliente no está permitido, mensaje explícito (nunca un 500).
        $request->validate(['master_client_id' => ['required', 'integer']]);

        try {
            $result = $this->accountStatement->detail(
                (int) $request->integer('master_client_id'),
                $request->filled('sales_project_id') ? (int) $request->integer('sales_project_id') : null,
                $request->filled('desde') ? $request->string('desde')->toString() : null,
                $request->filled('hasta') ? $request->string('hasta')->toString() : null,
            );
        } catch (AccountStatementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /** GET /api/admin-contab/account-statement/export?master_client_id=&sales_project_id=&desde=&hasta= — REQ-456 RN1/RN2. */
    public function exportExcel(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate(['master_client_id' => ['required', 'integer']]);

        try {
            $detail = $this->accountStatement->detail(
                (int) $request->integer('master_client_id'),
                $request->filled('sales_project_id') ? (int) $request->integer('sales_project_id') : null,
                $request->filled('desde') ? $request->string('desde')->toString() : null,
                $request->filled('hasta') ? $request->string('hasta')->toString() : null,
            );
        } catch (AccountStatementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $bytes    = $this->export->toExcel($detail);
        $filename = 'estado-cuenta-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(fn () => print $bytes, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
