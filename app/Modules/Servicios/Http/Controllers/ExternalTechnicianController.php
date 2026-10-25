<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Servicios\Exceptions\ExternalTechnicianDeactivationRequiresConfirmationException;
use App\Modules\Servicios\Http\Requests\StoreExternalTechnicianRequest;
use App\Modules\Servicios\Models\ExternalTechnician;
use App\Modules\Servicios\Services\ExternalTechnicianService;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Fase 4 — Servicios, Batch 5 (REQ-263→267). Técnicos externos. */
final class ExternalTechnicianController extends Controller
{
    // REQ-267 RN2 — costo/día y margen visibles únicamente para Lider Servicios/Líder de Servicios y
    // Gerencia (superadmin incluido por consistencia con el resto del módulo). Igual que
    // EnforceRole, se lee del claim `role` del JWT, no de una consulta a BD aparte.
    private const COST_VISIBILITY_ROLES = [RoleKeys::SUPERADMIN, RoleKeys::LIDER_SERVICIOS, RoleKeys::MANAGEMENT];

    public function __construct(private readonly ExternalTechnicianService $technicians) {}

    /** GET /api/servicios/external-technicians — REQ-263. */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'per_page' => (int) $request->query('per_page', 20),
        ];

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $filters['search'] = $search;
        }

        $estado = $request->query('estado');
        if (is_string($estado) && $estado !== '') {
            $filters['estado'] = $estado;
        }

        $page = $this->technicians->list($filters);
        $canSeeCosts = $this->canSeeCosts($request);

        return response()->json([
            'data'    => array_map(
                fn (ExternalTechnician $t): array => $this->summary($t, $canSeeCosts),
                $page->items(),
            ),
            'meta'    => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'counts'  => $this->technicians->counts(),
        ]);
    }

    /** GET /api/servicios/external-technicians/{id} — ficha (REQ-265/266/267). */
    public function show(Request $request, int $id): JsonResponse
    {
        $technician = ExternalTechnician::with('rateHistory.changedByUser')->find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $canSeeCosts = $this->canSeeCosts($request);

        $payload = [
            'id'                 => $technician->id,
            'nombre'             => $technician->nombre,
            'empresa'            => $technician->empresa,
            'especialidad'       => $technician->especialidad ?? 'Sin especificar',
            'telefono'           => $technician->telefono,
            'email'              => $technician->email,
            // RN3 (REQ-266) — "Mostrar tarifa" apagado reemplaza el monto por null (el frontend
            // muestra "Sin tarifa asignada"), mismo criterio que summary(). Nunca afecta el valor
            // real guardado ni el cálculo de cotización (eso sigue leyendo $technician->tarifa_dia).
            //
            // SCRUM-337 (rebote QA 2026-08-13, decisión de Luis en el daily 2026-08-18: bug, no
            // excepción intencional) — antes SOLO dependía de `tarifa_visible`, así que un técnico
            // interno sin `puede_ver_costos` (ej. Tecnico Servicios) veía la tarifa/día real de un externo con
            // el toggle activado, aunque RN2 dice que el costo/día es exclusivo de
            // Lider Servicios/Mark/David/Whil. Ahora exige ambos, igual que el resto de esta ficha.
            'tarifa_dia'         => ($technician->tarifa_visible && $canSeeCosts) ? $technician->tarifa_dia : null,
            'tarifa_visible'     => $technician->tarifa_visible,
            'estado'             => $technician->estado,
            'proyectos_activos'  => $this->technicians->activeProjectsCount($technician),
            'puede_ver_costos'   => $canSeeCosts,
            // REQ-267 — desde Batch 11, alimentado por ítems Subcontratado reales de Cotización
            // (ver ExternalTechnicianService::assignedProjects()). costo_dia/margen_percent solo
            // viajan cuando $canSeeCosts es true (SCRUM-337).
            'proyectos_asignados' => $this->technicians->assignedProjects($technician, $canSeeCosts),
        ];

        // SCRUM-337 (retest QA 2026-08-19) — rate_history filtraba tarifa_dia real por esta vía
        // aunque el campo principal de arriba ya estuviera bien gateado: key ausente (no vacía)
        // cuando el actor no puede ver costos, mismo criterio que costo_dia/margen_percent.
        if ($canSeeCosts) {
            $payload['rate_history'] = $technician->rateHistory->map(fn ($h): array => [
                'tarifa_dia' => $h->tarifa_dia,
                'changed_by' => $h->changedByUser?->first_name,
                'created_at' => $h->created_at->toIso8601String(),
            ])->all();
        }

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — solo cuando la tarifa real efectivamente
        // viaja en la respuesta (canSeeCosts + tarifa_visible); si queda en null no hay dato
        // sensible que auditar, ver el criterio "no cada GET" de la epic.
        if ($canSeeCosts && $technician->tarifa_visible) {
            app(AuditService::class)->log(
                action:     AuditService::SERVICIOS_EXTERNAL_TECHNICIAN_VIEWED,
                entityType: 'external_technician',
                entityId:   $technician->id,
            );
        }

        return response()->json($payload);
    }

    /** POST /api/servicios/external-technicians — REQ-264 (RN4: rol validado en la ruta). */
    public function store(StoreExternalTechnicianRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{nombre: string, empresa: string, especialidad?: ?string, telefono?: ?string, email?: ?string, tarifa_dia: float} $validated */
        $validated = $request->validated();

        $technician = $this->technicians->create($validated, $user);

        return response()->json($this->summary($technician, $this->canSeeCosts($request)), 201);
    }

    /** PATCH /api/servicios/external-technicians/{id}/tarifa — REQ-266 (RN1: rol validado en la ruta). */
    public function updateTarifa(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $technician = ExternalTechnician::find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{tarifa_dia?: float|null, tarifa_visible?: bool|null} $validated */
        $validated = $request->validate([
            'tarifa_dia'     => ['nullable', 'numeric', 'min:0'],
            'tarifa_visible' => ['nullable', 'boolean'],
        ]);

        $technician = $this->technicians->updateTarifa($technician, $validated, $user);

        return response()->json($this->summary($technician, $this->canSeeCosts($request)));
    }

    /** PATCH /api/servicios/external-technicians/{id}/estado — REQ-265 (RN1: rol validado en la ruta). */
    public function updateEstado(Request $request, int $id): JsonResponse
    {
        $technician = ExternalTechnician::find($id);
        if ($technician === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{estado: string, confirm?: bool} $validated */
        $validated = $request->validate([
            'estado'  => ['required', 'string', Rule::in([ExternalTechnician::ESTADO_ACTIVE, ExternalTechnician::ESTADO_INACTIVE])],
            'confirm' => ['nullable', 'boolean'],
        ]);

        try {
            $technician = $this->technicians->updateEstado(
                $technician,
                $validated['estado'],
                (bool) ($validated['confirm'] ?? false),
            );
        } catch (ExternalTechnicianDeactivationRequiresConfirmationException $e) {
            return response()->json([
                'message'               => $e->getMessage(),
                'requires_confirmation' => true,
                'active_projects_count' => $e->activeProjectsCount(),
            ], 409);
        }

        return response()->json($this->summary($technician, $this->canSeeCosts($request)));
    }

    /**
     * @return array<string, mixed>
     * SCRUM-337 (retest QA 2026-08-19, 2do CRÍTICO) — el fix anterior gateó tarifa_dia en show()
     * (la ficha) pero nunca en summary() (usado por index()/store()/updateTarifa()/updateEstado()
     * — la tabla de listado le seguía mostrando la tarifa real a un técnico interno con el toggle
     * activado). Mismo criterio ya establecido: exige tarifa_visible Y puede_ver_costos.
     */
    private function summary(ExternalTechnician $t, bool $canSeeCosts): array
    {
        return [
            'id'                => $t->id,
            'nombre'            => $t->nombre,
            'empresa'           => $t->empresa,
            'especialidad'      => $t->especialidad ?? 'Sin especificar',
            'telefono'          => $t->telefono,
            'tarifa_dia'        => ($t->tarifa_visible && $canSeeCosts) ? $t->tarifa_dia : null,
            'tarifa_visible'    => $t->tarifa_visible,
            'estado'            => $t->estado,
            'proyectos_activos' => $this->technicians->activeProjectsCount($t),
        ];
    }

    private function canSeeCosts(Request $request): bool
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return is_array($payload) && isset($payload['role']) && in_array($payload['role'], self::COST_VISIBILITY_ROLES, true);
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
