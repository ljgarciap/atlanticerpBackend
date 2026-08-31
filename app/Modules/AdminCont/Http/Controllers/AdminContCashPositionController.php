<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\DailyCashCountStateException;
use App\Modules\AdminCont\Http\Requests\UpdateDailyCashCountEntryRequest;
use App\Modules\AdminCont\Http\Requests\UpdateDailyCashCountGeneralObservationRequest;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Services\CashPositionExportService;
use App\Modules\AdminCont\Services\CashPositionService;
use App\Modules\AdminCont\Services\DailyCashCountService;
use App\Modules\AdminCont\Services\DailyCashCountSummaryPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-520→524) — Arqueo / Flujo de Caja, parte 1. Ver
 * docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md para el contrato completo y los gates de rol
 * exactos de cada ruta (routes/admin-contab.php).
 *
 * Batch 19 (SCRUM-602→606, REQ-525→529) — ver docs/adr/ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md.
 * `dailyCount()` pasa a devolver el arqueo ACTIVO (no necesariamente hoy). Agrega cerrar/historial/
 * detalle/aprobar/exportar resumen.
 */
class AdminContCashPositionController
{
    public function __construct(
        private readonly CashPositionService $cashPosition,
        private readonly CashPositionExportService $export,
        private readonly DailyCashCountService $dailyCashCount,
        private readonly DailyCashCountSummaryPdfService $summaryPdf,
    ) {}

    /** GET /api/admin-contab/cash-position — REQ-520, nunca depende de la ventana elegida. */
    public function header(): JsonResponse
    {
        return response()->json($this->cashPosition->header());
    }

    /**
     * GET /api/admin-contab/cash-position/projected?window=0|30|90 — REQ-521.
     * Aclarado por Luis 2026-08-25 (Pre-QA Batch 18): Yaneth puede pedir este endpoint (para las 3
     * tarjetas del encabezado, REQ-520) pero nunca recibe el detalle línea por línea — eso es lo
     * que REQ-521 le veda. Los totales/neto siempre se calculan sobre el dato completo.
     */
    public function projected(Request $request): JsonResponse
    {
        $windowDays = $this->windowFromRequest($request);
        if ($windowDays === null) {
            return response()->json(['message' => 'window debe ser 0, 30 o 90.'], 422);
        }

        $data = $this->cashPosition->projected($windowDays);
        if ($this->roleFromRequest($request) === 'asistente_administrativa') {
            $data['entradas'] = [];
            $data['salidas'] = [];
        }

        return response()->json($data);
    }

    /** GET /api/admin-contab/cash-position/real?window=30|90 — REQ-523. */
    public function real(Request $request): JsonResponse
    {
        $windowDays = $this->windowFromRequest($request);
        if ($windowDays === null || $windowDays === 0) {
            return response()->json(['message' => 'window debe ser 30 o 90.'], 422);
        }

        return response()->json($this->cashPosition->real($windowDays));
    }

    /** GET /api/admin-contab/cash-position/daily-count — REQ-524/527, el arqueo ACTIVO. */
    public function dailyCount(): JsonResponse
    {
        return response()->json($this->dailyCashCount->active());
    }

    /** PUT /api/admin-contab/cash-position/daily-count/entries — REQ-524 RN2. */
    public function updateDailyCashCountEntry(UpdateDailyCashCountEntryRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $this->dailyCashCount->updateEntryObservation($data['movement_type'], $data['movement_id'], $data['observacion'] ?? null);
        } catch (DailyCashCountStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(status: 204);
    }

    /** PUT /api/admin-contab/cash-position/daily-count/general-observation — REQ-524 RN4. */
    public function updateDailyCashCountGeneralObservation(UpdateDailyCashCountGeneralObservationRequest $request): JsonResponse
    {
        try {
            $this->dailyCashCount->updateGeneralObservation($request->validated('observacion'));
        } catch (DailyCashCountStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(status: 204);
    }

    /** POST /api/admin-contab/cash-position/daily-count/close — REQ-526. */
    public function closeDailyCashCount(Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $result = $this->dailyCashCount->close($actor->id);
        } catch (DailyCashCountStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    /** GET /api/admin-contab/cash-position/daily-count/export — REQ-529, arqueo activo. */
    public function dailyCountExport(): StreamedResponse
    {
        $data = $this->dailyCashCount->active();
        $bytes = $this->summaryPdf->toPdf($data);
        $filename = "arqueo-{$data['fecha']}-".now()->format('Ymd-His').'.pdf';

        return response()->streamDownload(fn () => print $bytes, $filename, ['Content-Type' => 'application/pdf']);
    }

    /** GET /api/admin-contab/cash-position/history?page= — REQ-528. */
    public function history(Request $request): JsonResponse
    {
        return response()->json($this->dailyCashCount->history($request->integer('page', 1)));
    }

    /**
     * GET /api/admin-contab/cash-position/history/{id} — REQ-528/529. `puede_aprobar` (REQ-528
     * RN2) es dependiente del actor, no del arqueo — mismo criterio que
     * `puede_decidir_incobrable`/`puede_aprobar_rechazar` en el resto del módulo
     * (AdminContInvoiceController::detail()/AdminContCreditNoteController), calculado acá porque
     * esta ruta no está gateada por `primary_approver_only` (accesible a Felix/Yaneth/Mark/Gerencia) — el
     * frontend lo usa solo para mostrar/ocultar el botón "Aprobar", nunca decide por su cuenta
     * quién es Mark.
     */
    public function historyDetail(int $id, Request $request): JsonResponse
    {
        $data = $this->dailyCashCount->detail($id);
        if ($data === null) {
            return response()->json(['message' => 'Arqueo no encontrado.'], 404);
        }

        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        // superadmin.all bypassa el gate de Mark (decisión de Luis, 2026-08-22) — mismo criterio
        // que EnsureIsPrimaryApprover, que ya lo aplica sobre la acción real de aprobar.
        $primaryApproverId = AdminContFiscalSettings::current()->primary_approver_user_id;
        $isMark = in_array('superadmin.all', $permissions, true)
            || ($payload !== null && isset($payload['sub']) && $primaryApproverId !== null
                && (int) $payload['sub'] === $primaryApproverId);

        $data['puede_aprobar'] = $data['estado'] === AdminContDailyCashCount::ESTADO_PENDIENTE_APROBACION && $isMark;

        return response()->json($data);
    }

    /** GET /api/admin-contab/cash-position/history/{id}/export — REQ-529, arqueo del historial. */
    public function historyExport(int $id): StreamedResponse|JsonResponse
    {
        $data = $this->dailyCashCount->detail($id);
        if ($data === null) {
            return response()->json(['message' => 'Arqueo no encontrado.'], 404);
        }

        $bytes = $this->summaryPdf->toPdf($data);
        $filename = "arqueo-{$data['fecha']}-".now()->format('Ymd-His').'.pdf';

        return response()->streamDownload(fn () => print $bytes, $filename, ['Content-Type' => 'application/pdf']);
    }

    /** POST /api/admin-contab/cash-position/history/{id}/approve — REQ-528 RN2/RN4, exclusivo Mark. */
    public function approveDailyCashCount(int $id, Request $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            $result = $this->dailyCashCount->approve($id, $actor->id);
        } catch (DailyCashCountStateException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    /**
     * GET /api/admin-contab/cash-position/export?view=proyectado|real&window=0|30|90&format=pdf|excel
     * REQ-520 RN3 — nunca disponible para view=real&window=0 (Arqueo del día, tiene su propio
     * "Descargar resumen" en Batch 19).
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $view = $request->string('view')->toString();
        $format = $request->string('format')->toString();
        if (! in_array($view, ['proyectado', 'real'], true) || ! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json(['message' => 'view debe ser "proyectado" o "real", format debe ser "pdf" o "excel".'], 422);
        }

        $windowDays = $this->windowFromRequest($request);
        if ($windowDays === null) {
            return response()->json(['message' => 'window debe ser 0, 30 o 90.'], 422);
        }
        if ($view === 'real' && $windowDays === 0) {
            return response()->json(['message' => 'El Arqueo del día (Real + Hoy) no se exporta desde acá.'], 422);
        }

        $data = $view === 'proyectado' ? $this->cashPosition->projected($windowDays) : $this->cashPosition->real($windowDays);
        $bytes = $format === 'pdf' ? $this->export->toPdf($view, $data) : $this->export->toExcel($view, $data);
        $filename = "flujo-caja-{$view}-".now()->format('Ymd-His').($format === 'pdf' ? '.pdf' : '.xlsx');
        $mime = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(fn () => print $bytes, $filename, ['Content-Type' => $mime]);
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

    private function windowFromRequest(Request $request): ?int
    {
        $window = $request->integer('window', 30);

        return in_array($window, [0, 30, 90], true) ? $window : null;
    }

    private function roleFromRequest(Request $request): ?string
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return $payload['role'] ?? null;
    }
}
