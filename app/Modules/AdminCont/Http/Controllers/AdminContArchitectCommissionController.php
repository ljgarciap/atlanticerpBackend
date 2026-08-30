<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\ArchitectCommissionStateException;
use App\Modules\AdminCont\Http\Requests\DecideArchitectPercentRequest;
use App\Modules\AdminCont\Http\Requests\ProposeArchitectPercentRequest;
use App\Modules\AdminCont\Http\Requests\UpdateArchitectCuentaPagoRequest;
use App\Modules\AdminCont\Http\Requests\UpdateArchitectFiscalProfileRequest;
use App\Modules\AdminCont\Http\Requests\UploadArchitectCuentaCobroRequest;
use App\Modules\AdminCont\Http\Requests\UploadComprobanteRetencionRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\ArchitectCommission;
use App\Modules\AdminCont\Services\ArchitectCommissionService;
use App\Modules\VentasDiseno\Models\Architect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 16 de Admin&Cont (SCRUM-585→590, REQ-508→513) — Comisiones Externas. Ver
 * docs/adr/ADR-SCRUM585-590-batch16-comisiones-externas.md.
 * Batch 17 (SCRUM-591→596, REQ-514→519) agrega comprobante de retención, marcar pagado, % por
 * proyecto (propuesta/decisión), cuenta de pago editable y recordatorio por correo. Ver
 * docs/adr/ADR-SCRUM591-596-batch17-comisiones-externas.md.
 *
 * Sin lógica de autorización propio-vs-ajeno acá (a diferencia de
 * `AdminContInternalCommissionController`): la audiencia de esta pantalla es Felix/Yaneth/Mark/
 * Gerencia únicamente (REQ-508→512, ningún arquitecto ni vendedor la consulta), gateada por rol
 * directo en `routes/admin-contab.php` — no hace falta el patrón "vendedor solo ve lo suyo".
 */
class AdminContArchitectCommissionController
{
    public function __construct(private readonly ArchitectCommissionService $commissions) {}

    /** GET /api/admin-contab/commissions/external?search=&mes=&architect_id=&estado= — REQ-508/509. */
    public function summary(Request $request): JsonResponse
    {
        /** @var array{search?: string, mes?: string, architect_id?: int, estado?: string} $filters */
        $filters = [];
        if ($request->filled('search')) {
            $filters['search'] = $request->string('search')->toString();
        }
        if ($request->filled('mes')) {
            $filters['mes'] = $request->string('mes')->toString();
        }
        if ($request->filled('architect_id')) {
            $filters['architect_id'] = (int) $request->integer('architect_id');
        }
        if ($request->filled('estado')) {
            $filters['estado'] = $request->string('estado')->toString();
        }

        $summary = $this->commissions->summary($filters);

        // REQ-516 RN4 — mismo criterio que `AdminContInternalCommissionController::isMarkApprover()`
        // y `AdminContInvoiceController::detail()`: dependiente del actor, no del proyecto, se
        // calcula acá (este endpoint es de acceso general, no gateado por `mark_only`) para que el
        // frontend sepa cuándo mostrar Aprobar/Rechazar en el modal de detalle (REQ-519).
        $summary['puede_decidir_porcentaje'] = $this->isMarkApprover($request);

        return response()->json($summary);
    }

    /** GET /api/admin-contab/commissions/external/architects — opciones del filtro de arquitecto (REQ-509). */
    public function architectOptions(): JsonResponse
    {
        $architectIds = $this->commissions->allProjects()->pluck('architect_id')->unique()->values();

        $architects = Architect::whereIn('id', $architectIds)->orderBy('name')->get(['id', 'name']);

        return response()->json($architects->map(fn (Architect $a) => [
            'id'     => $a->id,
            'nombre' => $a->name,
        ])->values());
    }

    /** PUT /api/admin-contab/commissions/external/architects/{architectId}/fiscal-profile — REQ-510. */
    public function updateFiscalProfile(UpdateArchitectFiscalProfileRequest $request, int $architectId): JsonResponse
    {
        $architect = Architect::find($architectId);
        if ($architect === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{empresa: string, ruc: string, regimen_fiscal: string} $validated */
        $validated = $request->validated();

        $profile = $this->commissions->upsertFiscalProfile(
            $architectId,
            $validated['empresa'],
            $validated['ruc'],
            $validated['regimen_fiscal'],
        );

        return response()->json($profile);
    }

    /** POST /api/admin-contab/commissions/external/projects/{pipelineCardId}/cuenta-cobro — REQ-513. */
    public function uploadCuentaCobro(UploadArchitectCuentaCobroRequest $request, int $pipelineCardId): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // El arquitecto del proyecto se resuelve server-side desde el propio join comisionable
        // (nunca del cliente HTTP) — si el proyecto no existe o no tiene arquitecto asociado, 404.
        $project = $this->commissions->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $file       = $request->file('file');
        $commission = $this->commissions->uploadCuentaCobro(
            $pipelineCardId,
            (int) $project['architect_id'],
            $file,
            $actor->id,
        );

        return response()->json([
            'nombre_archivo' => $commission->cuenta_cobro_nombre_archivo,
            'uploaded_at'    => $commission->cuenta_cobro_uploaded_at?->toIso8601String(),
        ], 201);
    }

    /** GET /api/admin-contab/commissions/external/projects/{pipelineCardId}/cuenta-cobro — ver el
     *  archivo subido (URL firmada 15 min, mismo TTL que el resto del módulo). */
    public function showCuentaCobro(int $pipelineCardId): JsonResponse
    {
        $commission = ArchitectCommission::where('pipeline_card_id', $pipelineCardId)->first();
        if ($commission === null || $commission->cuenta_cobro_storage_key === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['url' => $this->commissions->cuentaCobroPresignedUrl($commission)]);
    }

    /** POST .../projects/{pipelineCardId}/comprobante-retencion — REQ-514. */
    public function uploadComprobanteRetencion(UploadComprobanteRetencionRequest $request, int $pipelineCardId): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $project = $this->commissions->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $file = $request->file('file');

        try {
            $commission = $this->commissions->uploadComprobanteRetencion(
                $pipelineCardId,
                (int) $project['architect_id'],
                $file,
                $actor->id,
            );
        } catch (ArchitectCommissionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'nombre_archivo' => $commission->comprobante_retencion_nombre_archivo,
            'uploaded_at'    => $commission->comprobante_retencion_uploaded_at?->toIso8601String(),
        ], 201);
    }

    /** GET .../projects/{pipelineCardId}/comprobante-retencion — URL firmada, mismo TTL. */
    public function showComprobanteRetencion(int $pipelineCardId): JsonResponse
    {
        $commission = ArchitectCommission::where('pipeline_card_id', $pipelineCardId)->first();
        if ($commission === null || $commission->comprobante_retencion_storage_key === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['url' => $this->commissions->comprobanteRetencionPresignedUrl($commission)]);
    }

    /** GET /api/admin-contab/commissions/external/bank-accounts — opciones de cuenta de pago (REQ-517). */
    public function bankAccountOptions(): JsonResponse
    {
        return response()->json($this->commissions->bankAccountOptions());
    }

    /** PUT .../projects/{pipelineCardId}/cuenta-pago — REQ-517. */
    public function updateCuentaPago(UpdateArchitectCuentaPagoRequest $request, int $pipelineCardId): JsonResponse
    {
        $project = $this->commissions->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{bank_account_id?: int|null} $validated */
        $validated = $request->validated();

        try {
            $commission = $this->commissions->actualizarCuentaPago(
                $pipelineCardId,
                (int) $project['architect_id'],
                $validated['bank_account_id'] ?? null,
            );
        } catch (ArchitectCommissionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['bank_account_id' => $commission->bank_account_id]);
    }

    /** POST .../projects/{pipelineCardId}/percent/propose — REQ-516. */
    public function proposePercent(ProposeArchitectPercentRequest $request, int $pipelineCardId): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $project = $this->commissions->allProjects()->firstWhere('pipeline_card_id', $pipelineCardId);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{porcentaje: float, motivo: string} $validated */
        $validated = $request->validated();

        try {
            $commission = $this->commissions->proponerPorcentaje(
                $pipelineCardId,
                (int) $project['architect_id'],
                (float) $validated['porcentaje'],
                $validated['motivo'],
                $actor->id,
            );
        } catch (ArchitectCommissionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['porcentaje_pendiente' => $commission->porcentaje_pendiente]);
    }

    /** POST .../projects/{pipelineCardId}/percent/decide — REQ-516, exclusivo de Mark. */
    public function decidePercent(DecideArchitectPercentRequest $request, int $pipelineCardId): JsonResponse
    {
        /** @var array{approve: bool} $validated */
        $validated = $request->validated();

        try {
            $commission = $this->commissions->decidirPorcentaje($pipelineCardId, (bool) $validated['approve']);
        } catch (ArchitectCommissionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['porcentaje_aprobado' => $commission->porcentaje_aprobado]);
    }

    /** POST .../projects/{pipelineCardId}/mark-paid — REQ-515. */
    public function markPaid(int $pipelineCardId): JsonResponse
    {
        try {
            $commission = $this->commissions->marcarComoPagada($pipelineCardId);
        } catch (ArchitectCommissionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['fecha_pago' => $commission->fecha_pago?->toDateString()]);
    }

    /** POST .../projects/{pipelineCardId}/remind — REQ-518. */
    public function sendReminder(int $pipelineCardId): JsonResponse
    {
        try {
            $this->commissions->enviarRecordatorio($pipelineCardId);
        } catch (ArchitectCommissionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'ok']);
    }

    /** Mismo criterio que `AdminContInternalCommissionController::isMarkApprover()` —
     *  `superadmin.all` bypassa, si no hay `mark_approver_user_id` configurado nadie es Mark. */
    private function isMarkApprover(Request $request): bool
    {
        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');
        if ($payload === null || ! isset($payload['sub'])) {
            return false;
        }

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        if (in_array('superadmin.all', $permissions, true)) {
            return true;
        }

        $markApproverId = AdminContFiscalSettings::current()->mark_approver_user_id;

        return $markApproverId !== null && (int) $payload['sub'] === $markApproverId;
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
}
