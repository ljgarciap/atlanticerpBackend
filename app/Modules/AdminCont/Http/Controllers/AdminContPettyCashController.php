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

    /** GET /api/admin-contab/petty-cash/pending — REQ-537. */
    public function pending(): JsonResponse
    {
        return response()->json($this->pettyCash->pending());
    }

    /** GET /api/admin-contab/petty-cash/rejected — REQ-536, tab "Rechazados". */
    public function rejected(): JsonResponse
    {
        return response()->json($this->pettyCash->rejected());
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

    /** GET /api/admin-contab/petty-cash/reports — REQ-539. */
    public function reports(): JsonResponse
    {
        return response()->json($this->pettyCash->reports());
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
        $detail['puede_aprobar'] = $detail['estado'] === AdminContPettyCashReport::ESTADO_PENDIENTE_APROBACION && $this->isMarkApprover($request);

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
     *  middleware `mark_only` en la ruta (ver routes/admin-contab.php). */
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
            && $this->isMarkApprover($request);
        $detail['puede_reabrir'] = $detail['ubicacion'] === 'rechazados' && $this->isFelixOrYaneth($request);

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

    /** PUT /api/admin-contab/petty-cash/expenses/{id}/reject — REQ-541, exclusivo Mark
     *  (`mark_only` en la ruta). */
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

    /** PUT /api/admin-contab/petty-cash/reports/{numero}/reject — REQ-541, exclusivo Mark
     *  (`mark_only` en la ruta). */
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

    /** PUT /api/admin-contab/petty-cash/expenses/{id}/reopen — REQ-544, Felix/Yaneth (mismo
     *  roster que `storeExpenses`, deliberadamente SIN `mark_only` — reabrir no es de Mark). */
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

    /** Redundante con el gate `mark_only` de la ruta de aprobar (ver docblock ahí) — se reusa acá
     *  solo para calcular `puede_aprobar` en el detalle, que SÍ es visible para Felix/Yaneth/
     *  Gerencia (no está detrás de `mark_only`). Mismo criterio que
     *  `AdminContCashPositionController::historyDetail()`. */
    private function isMarkApprover(Request $request): bool
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

        $markApproverId = AdminContFiscalSettings::current()->mark_approver_user_id;

        return $payload !== null && isset($payload['sub']) && $markApproverId !== null
            && (int) $payload['sub'] === $markApproverId;
    }

    /** Batch 21 — mismo roster de rol que el grupo `role:superadmin,lider_admin_contab,
     *  asistente_administrativa` que protege `PUT /expenses/{id}/reopen` (ver routes/admin-contab.php).
     *  Se duplica acá solo para calcular `puede_reabrir` en el detalle, que SÍ es visible para
     *  Gerencia (no está detrás de ese `role:`) — mismo criterio que `isMarkApprover()`. */
    private function isFelixOrYaneth(Request $request): bool
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return in_array($payload['role'] ?? null, ['superadmin', 'lider_admin_contab', 'asistente_administrativa'], true);
    }
}
