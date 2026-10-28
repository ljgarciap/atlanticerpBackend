<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\PettyCashStateException;
use App\Modules\AdminCont\Http\Requests\AddPettyCashExpenseAttachmentRequest;
use App\Modules\AdminCont\Http\Requests\RejectPettyCashExpenseRequest;
use App\Modules\AdminCont\Http\Requests\RejectPettyCashReportRequest;
use App\Modules\AdminCont\Http\Requests\ReopenPettyCashExpenseRequest;
use App\Modules\AdminCont\Http\Requests\StorePettyCashExpensesRequest;
use App\Modules\AdminCont\Http\Requests\StorePettyCashReportRequest;
use App\Modules\AdminCont\Http\Requests\UpdatePettyCashExpenseRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContPettyCashExpense;
use App\Modules\AdminCont\Models\AdminContPettyCashExpenseAttachment;
use App\Modules\AdminCont\Models\AdminContPettyCashReport;
use App\Modules\AdminCont\Services\AdminContPettyCashAttachmentService;
use App\Modules\AdminCont\Services\AdminContPettyCashReportPdfService;
use App\Modules\AdminCont\Services\AdminContPettyCashService;
use App\Modules\Auth\Support\RoleKeys;
use App\Shared\Http\Pagination;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 20 de Admin&Cont (SCRUM-612→617, REQ-535→540) — Caja Chica. Ver
 * `AdminContPettyCashService` para la lógica de negocio; este controller solo resuelve el actor,
 * traduce excepciones de dominio a códigos HTTP y da forma a la respuesta.
 * Batch 21 (SCRUM-618→623, REQ-541→546) agrega rechazo/reapertura/edición/detalle unificado.
 */
class AdminContPettyCashController
{
    public function __construct(
        private readonly AdminContPettyCashService $pettyCash,
        private readonly AdminContPettyCashAttachmentService $attachments,
        private readonly AdminContPettyCashReportPdfService $pdf,
    ) {}

    /** GET /api/admin-contab/petty-cash/summary — REQ-536. */
    public function summary(): JsonResponse
    {
        return response()->json($this->pettyCash->summary());
    }

    /**
     * GET /api/admin-contab/petty-cash/pending — REQ-537. SCRUM-947 — paginación estándar A
     * NIVEL DE GRUPO (nunca parte las líneas de un mismo solicitante entre 2 páginas): 20
     * solicitantes por página, no 20 líneas. `total_general` sigue siendo el total real de TODO
     * lo pendiente, no solo la página (mismo criterio que `indicadores` en Saldo a Favor).
     */
    public function pending(Request $request): JsonResponse
    {
        $result = $this->pettyCash->pending();
        $pagination = Pagination::params($request, [], 'solicitante_nombre');
        $paginator = Pagination::ofArray(array_values($result['grupos']), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'grupos' => $paginator->items(),
            'total_general' => $result['total_general'],
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * GET /api/admin-contab/petty-cash/rejected — REQ-536, tab "Rechazados". SCRUM-947 —
     * paginación estándar sobre `lineas`.
     */
    public function rejected(Request $request): JsonResponse
    {
        $result = $this->pettyCash->rejected();
        $pagination = Pagination::params($request, [], 'fecha', 'desc');
        $paginator = Pagination::ofArray(array_values($result['lineas']), $pagination['per_page'], $pagination['page']);

        return response()->json(['lineas' => $paginator->items(), 'meta' => Pagination::meta($paginator)]);
    }

    /** POST /api/admin-contab/petty-cash/expenses — REQ-535, multi-línea. */
    public function storeExpenses(StorePettyCashExpensesRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var list<array{fecha: string, solicitante_id: int, proveedor: string, descripcion: string, monto_bruto: float, itbms: float, foto: UploadedFile}> $lineas */
        $lineas = $request->validated('lineas');

        try {
            $created = $this->pettyCash->registerExpenses($lineas, $user->id);
        } catch (CircuitOpenException) {
            return response()->json([
                'message' => 'Storage service temporarily unavailable. Please try again in a few minutes.',
            ], 503);
        }

        return response()->json([
            'created' => array_map(static fn (AdminContPettyCashExpense $e) => $e->id, $created),
        ], 201);
    }

    /** POST /api/admin-contab/petty-cash/reports — REQ-538. */
    public function storeReport(StorePettyCashReportRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var list<int> $expenseIds */
        $expenseIds = $request->validated('expense_ids');
        /** @var string $formaPago */
        $formaPago = $request->validated('forma_pago');

        try {
            $report = $this->pettyCash->generateReport($expenseIds, $formaPago, $user->id);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['numero' => $report->numero], 201);
    }

    /** GET /api/admin-contab/petty-cash/reports — REQ-539. SCRUM-947 — paginación estándar. */
    public function reports(Request $request): JsonResponse
    {
        $pagination = Pagination::params($request, [], 'fecha_creacion', 'desc');
        $paginator = Pagination::ofArray(array_values($this->pettyCash->reports()), $pagination['per_page'], $pagination['page']);

        return response()->json(['data' => $paginator->items(), 'meta' => Pagination::meta($paginator)]);
    }

    /** GET /api/admin-contab/petty-cash/reports/{numero} — REQ-539/540. `puede_aprobar` depende
     *  del actor, mismo criterio que `AdminContCashPositionController::historyDetail()`. */
    public function reportDetail(string $numero, Request $request): JsonResponse
    {
        $detail = $this->pettyCash->reportDetail($numero);
        if ($detail === null) {
            return response()->json(['message' => 'Reporte no encontrado.'], 404);
        }

        // Campo plano (no anidado en "acciones") — mismo shape que `DailyCashCountHistoryDetail`
        // en `types/adminContab.ts` del frontend, por consistencia con el resto del módulo.
        $detail['puede_aprobar'] = $detail['estado'] === AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION && $this->isPrimaryApprover($request);

        return response()->json($detail);
    }

    /** GET /api/admin-contab/petty-cash/reports/{numero}/pdf — REQ-539 RN3, "Descargar". */
    public function reportPdf(string $numero): StreamedResponse|JsonResponse
    {
        $detail = $this->pettyCash->reportDetail($numero);
        if ($detail === null) {
            return response()->json(['message' => 'Reporte no encontrado.'], 404);
        }

        // Senior Review hallazgo #2 — un reporte rechazado se disolvió (sus líneas ya no le
        // pertenecen, ver docblock de `AdminContPettyCashReport::ESTADO_RECHAZADO`), no hay
        // contenido real que generar. Defensa en profundidad: el frontend también esconde el
        // botón para este estado.
        if ($detail['estado'] === AdminContPettyCashReport::ESTADO_RECHAZADO) {
            return response()->json(['message' => 'Este reporte fue rechazado — ya no tiene contenido para descargar.'], 409);
        }

        $bytes = $this->pdf->toPdf($detail);
        $filename = "caja-chica-{$numero}.pdf";

        return response()->streamDownload(fn () => print $bytes, $filename, ['Content-Type' => 'application/pdf']);
    }

    /** PUT /api/admin-contab/petty-cash/reports/{numero}/approve — REQ-540, gate real es el
     *  middleware `primary_approver_only` en la ruta (ver routes/admin-contab.php). */
    public function approveReport(string $numero, Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $report = AdminContPettyCashReport::where('numero', $numero)->first();
        if ($report === null) {
            return response()->json(['message' => 'Reporte no encontrado.'], 404);
        }

        try {
            $this->pettyCash->approveReport($report, $user->id);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['numero' => $report->numero, 'estado' => $report->estado]);
    }

    /** GET /api/admin-contab/petty-cash/expenses/{id} — REQ-545, modal unificado de detalle. */
    public function expenseDetail(int $id, Request $request): JsonResponse
    {
        $detail = $this->pettyCash->expenseDetail($id);
        if ($detail === null) {
            return response()->json(['message' => 'Gasto no encontrado.'], 404);
        }

        $detail['puede_rechazar'] = $detail['ubicacion'] === 'reporte'
            && $detail['reporte_estado'] === AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION
            && $this->isPrimaryApprover($request);
        $detail['puede_reabrir'] = $detail['ubicacion'] === 'rechazados' && $this->isContabilidadOrAsistente($request);

        return response()->json($detail);
    }

    /** PUT /api/admin-contab/petty-cash/expenses/{id} — REQ-545 RN1/RN4, editar línea. */
    public function updateExpense(int $id, UpdatePettyCashExpenseRequest $request): JsonResponse
    {
        $expense = AdminContPettyCashExpense::find($id);
        if ($expense === null) {
            return response()->json(['message' => 'Gasto no encontrado.'], 404);
        }

        try {
            $this->pettyCash->updateExpense($expense, [
                'fecha' => $request->validated('fecha'),
                'proveedor' => $request->validated('proveedor'),
                'descripcion' => $request->validated('descripcion'),
                'monto_bruto' => (float) $request->validated('monto_bruto'),
                'itbms' => (float) $request->validated('itbms'),
            ]);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return $this->expenseDetail($id, $request);
    }

    /** POST /api/admin-contab/petty-cash/expenses/{id}/attachments — REQ-545 RN2, soporte adicional. */
    public function addExpenseAttachment(int $id, AddPettyCashExpenseAttachmentRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $expense = AdminContPettyCashExpense::find($id);
        if ($expense === null) {
            return response()->json(['message' => 'Gasto no encontrado.'], 404);
        }

        /** @var UploadedFile $foto */
        $foto = $request->validated('foto');

        try {
            $this->pettyCash->addExpenseAttachment($expense, $foto, $user->id);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (CircuitOpenException) {
            return response()->json([
                'message' => 'Storage service temporarily unavailable. Please try again in a few minutes.',
            ], 503);
        }

        return $this->expenseDetail($id, $request);
    }

    /** PUT /api/admin-contab/petty-cash/expenses/{id}/reject — REQ-541, exclusivo Gerencia
     *  (`primary_approver_only` en la ruta). */
    public function rejectExpense(int $id, RejectPettyCashExpenseRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $expense = AdminContPettyCashExpense::find($id);
        if ($expense === null) {
            return response()->json(['message' => 'Gasto no encontrado.'], 404);
        }

        /** @var string $motivo */
        $motivo = $request->validated('motivo');

        try {
            $result = $this->pettyCash->rejectExpense($expense, $motivo, $user->id);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    /** PUT /api/admin-contab/petty-cash/reports/{numero}/reject — REQ-541, exclusivo Gerencia
     *  (`primary_approver_only` en la ruta). */
    public function rejectReport(string $numero, RejectPettyCashReportRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $report = AdminContPettyCashReport::where('numero', $numero)->first();
        if ($report === null) {
            return response()->json(['message' => 'Reporte no encontrado.'], 404);
        }

        /** @var string $motivo */
        $motivo = $request->validated('motivo');

        try {
            $result = $this->pettyCash->rejectReport($report, $motivo, $user->id);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    /** PUT /api/admin-contab/petty-cash/expenses/{id}/reopen — REQ-544, Contabilidad/Asistente (mismo
     *  roster que `storeExpenses`, deliberadamente SIN `primary_approver_only` — reabrir no es de Gerencia). */
    public function reopenExpense(int $id, ReopenPettyCashExpenseRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $expense = AdminContPettyCashExpense::find($id);
        if ($expense === null) {
            return response()->json(['message' => 'Gasto no encontrado.'], 404);
        }

        /** @var string $motivo */
        $motivo = $request->validated('motivo');

        try {
            $this->pettyCash->reopenExpense($expense, $motivo, $user->id);
        } catch (PettyCashStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return $this->expenseDetail($id, $request);
    }

    /** GET /api/admin-contab/petty-cash/expenses/{expenseId}/attachments/{attachmentId}/url */
    public function attachmentUrl(int $expenseId, int $attachmentId): JsonResponse
    {
        $attachment = AdminContPettyCashExpenseAttachment::where('id', $attachmentId)
            ->where('expense_id', $expenseId)
            ->first();

        if ($attachment === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'url' => $this->attachments->presignedUrl($attachment),
            'filename' => $attachment->nombre_archivo,
        ]);
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

    /** Redundante con el gate `primary_approver_only` de la ruta de aprobar (ver docblock ahí) — se reusa acá
     *  solo para calcular `puede_aprobar` en el detalle, que SÍ es visible para Contabilidad/Asistente/
     *  Gerencia (no está detrás de `primary_approver_only`). Mismo criterio que
     *  `AdminContCashPositionController::historyDetail()`. */
    private function isPrimaryApprover(Request $request): bool
    {
        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        if (in_array('superadmin.all', $permissions, true)) {
            return true;
        }

        $primaryApproverId = AdminContFiscalSettings::current()->primary_approver_user_id;

        return $payload !== null && isset($payload['sub']) && $primaryApproverId !== null
            && (int) $payload['sub'] === $primaryApproverId;
    }

    /** Batch 21 — mismo roster de rol que el grupo `role:superadmin,lider_admin_contab,
     *  asistente_administrativa,lider_operaciones` que protege `PUT /expenses/{id}/reopen` (ver routes/admin-contab.php).
     *  Se duplica acá solo para calcular `puede_reabrir` en el detalle, que SÍ es visible para
     *  Gerencia (no está detrás de ese `role:`) — mismo criterio que `isPrimaryApprover()`. */
    private function isContabilidadOrAsistente(Request $request): bool
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return in_array($payload['role'] ?? null, [RoleKeys::SUPERADMIN, RoleKeys::LIDER_ADMIN_CONTAB, RoleKeys::ASISTENTE_ADMINISTRATIVA, RoleKeys::LIDER_OPERACIONES], true);
    }
}
