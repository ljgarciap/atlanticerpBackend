<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Http\Requests\StoreCommissionCaptureRequest;
use App\Modules\Servicios\Http\Requests\StoreInternalTechnicianRequest;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Services\CommissionCaptureService;
use App\Modules\Servicios\Services\InternalTechnicianService;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Modules\Servicios\Services\TechnicianStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Fase 4 — Servicios, Batch 6 (REQ-255→260) + Batch 7 (REQ-261/262/292). Técnicos internos. */
final class InternalTechnicianController extends Controller
{
    public function __construct(
        private readonly InternalTechnicianService $technicians,
        private readonly CommissionCaptureService $commission,
        private readonly ServiciosSettingsService $settings,
        private readonly TechnicianStatusService $status,
    ) {}

    /** GET /api/servicios/internal-technicians — REQ-255, vista Equipo. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->technicians->list()]);
    }

    /** GET /api/servicios/internal-technicians/stats — REQ-261, 3 tarjetas resumen. */
    public function stats(): JsonResponse
    {
        return response()->json($this->technicians->teamStats());
    }

    /**
     * GET /api/servicios/internal-technicians/agenda — REQ-260. RN2: `?technician_id=` filtra a
     * un único técnico. SCRUM-803 — `?view=day|week|month` (default day) + `?date=Y-m-d` (default
     * hoy) acotan el rango de visitas — ver `InternalTechnicianService::agenda()`.
     */
    public function agenda(Request $request): JsonResponse
    {
        $technicianId = $request->query('technician_id');
        $technicianId = is_string($technicianId) && $technicianId !== '' ? (int) $technicianId : null;

        $view = $request->query('view');
        $view = in_array($view, ['day', 'week', 'month'], true) ? $view : 'day';

        $date = $request->query('date');
        $date = is_string($date) && $date !== '' ? $date : null;

        return response()->json(['data' => $this->technicians->agenda($technicianId, $view, $date)]);
    }

    /** GET /api/servicios/internal-technicians/sla-settings — REQ-292 RN2. */
    public function slaSettings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->slaSettings()]);
    }

    /** PATCH /api/servicios/internal-technicians/sla-settings — REQ-292 RN2 (RN: rol validado en la ruta). */
    public function updateSlaSettings(Request $request): JsonResponse
    {
        /** @var array<string, int> $validated */
        $validated = $request->validate([
            'claim'        => ['sometimes', 'integer', 'min:1'],
            'warranty'     => ['sometimes', 'integer', 'min:1'],
            'installation' => ['sometimes', 'integer', 'min:1'],
            'retrofit'     => ['sometimes', 'integer', 'min:1'],
        ]);

        $this->settings->updateSlaSettings($validated);

        return response()->json(['data' => $this->settings->slaSettings()]);
    }

    /** GET /api/servicios/internal-technicians/{id}/visitas-hoy — REQ-257. */
    public function visitsToday(int $id): JsonResponse
    {
        $technician = InternalTechnician::find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->technicians->visitsToday($technician)]);
    }

    /**
     * GET /api/servicios/internal-technicians/{id}/commission-capture — REQ-292 RN6. Sin
     * `?year=&month=`, resuelve el mes en curso. `data: null` = "Pendiente de captura".
     */
    public function commissionCapture(Request $request, int $id): JsonResponse
    {
        $technician = InternalTechnician::find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $technician->has_bonus_plan) {
            return response()->json(['message' => 'Este técnico no tiene un plan de bonificación activo.'], 422);
        }

        $year  = $request->query('year');
        $month = $request->query('month');

        $data = $this->commission->forPeriod(
            $technician,
            is_numeric($year) ? (int) $year : null,
            is_numeric($month) ? (int) $month : null,
        );

        return response()->json(['data' => $data]);
    }

    /**
     * PUT /api/servicios/internal-technicians/{id}/commission-capture — REQ-292 (RN5: rol
     * validado en la ruta; editable incluso si el mes ya cerró).
     */
    public function saveCommissionCapture(StoreCommissionCaptureRequest $request, int $id): JsonResponse
    {
        $technician = InternalTechnician::find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $technician->has_bonus_plan) {
            return response()->json(['message' => 'Este técnico no tiene un plan de bonificación activo.'], 422);
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{year: int, month: int, satisfaccion_promedio: float, incidencias_puntualidad: int, calificacion_actitud: float, licencia_medica?: bool} $validated */
        $validated = $request->validated();

        return response()->json(['data' => $this->commission->save($technician, $validated, $user)]);
    }

    /**
     * GET /api/servicios/internal-technicians/{id}/commission — REQ-258, tarjeta+detalle de
     * comisión. RN8: exclusivo del propio técnico (Carlos) y Gerencia — ni Aaron
     * (`lider_servicios`, que sí puede CAPTURAR vía `commission-capture` sin ver el resultado) ni
     * otros técnicos. La ruta deja pasar por rol (para que Carlos, `tecnico_servicios`, llegue al
     * controller); esta verificación de "es este técnico o es Gerencia" es la que de verdad
     * implementa RN8, no reemplazable por `role:` a nivel de ruta.
     */
    public function commission(Request $request, int $id): JsonResponse
    {
        $technician = InternalTechnician::find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! $technician->has_bonus_plan) {
            return response()->json(['message' => 'Este técnico no tiene un plan de bonificación activo.'], 422);
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $roleKey  = $user->role?->key;
        $esGerencia = $roleKey === 'superadmin' || $roleKey === 'management';
        $esElMismo  = $technician->user_id !== null && $technician->user_id === $user->id;

        if (! $esGerencia && ! $esElMismo) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $year  = $request->query('year');
        $month = $request->query('month');

        $data = $this->commission->commissionForTechnician(
            $technician,
            is_numeric($year) ? (int) $year : null,
            is_numeric($month) ? (int) $month : null,
        );

        return response()->json(['data' => $data]);
    }

    /** POST /api/servicios/internal-technicians — REQ-259 (RN5: rol validado en la ruta). */
    public function store(StoreInternalTechnicianRequest $request): JsonResponse
    {
        /** @var array{nombre: string, telefono?: ?string, email?: ?string, especialidad: string} $validated */
        $validated = $request->validated();

        $technician = $this->technicians->create($validated);

        return response()->json([
            'id'           => $technician->id,
            'nombre'       => $technician->nombre,
            'telefono'     => $technician->telefono,
            'email'        => $technician->email,
            'especialidad' => $technician->especialidad,
            'color'        => $technician->color,
            // RN4 — un técnico nuevo siempre inicia sin visitas hoy (recién creado, no hay forma
            // de que ya tenga una) — calculado vía el mismo servicio que usa la vista Equipo
            // (`TechnicianStatusService::statusFor()`, colección vacía), no hardcodeado, para que
            // este 201 nunca pueda divergir de lo que un GET /internal-technicians inmediato
            // muestra (bug real SCRUM-322: el valor estaba hardcodeado en 'available' acá pero el
            // cálculo real devolvía 'off' siempre para técnicos sin user_id — ver fix en
            // TechnicianStatusService::statusFor()). Normalmente será 'available' (dentro de
            // horario laboral) u 'off' (fuera de horario), nunca 'en_route'/'on_site' porque no
            // hay tickets todavía.
            'estado'       => $this->status->statusFor($technician, collect()),
        ], 201);
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
