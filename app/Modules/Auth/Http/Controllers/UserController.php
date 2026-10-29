<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Requests\CreateUserRequest;
use App\Modules\Auth\Http\Requests\UpdateUserRequest;
use App\Modules\Auth\Models\Department;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Services\UatVisibilityService;
use App\Modules\Auth\Services\PasswordResetService;
use App\Modules\Auth\Services\UserService;
use App\Modules\Auth\Support\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $service,
        private readonly UatVisibilityService $uatVisibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search  = (string) ($request->query('search', ''));
        $perPage = min((int) ($request->query('per_page', 20)), 100);
        $active  = $request->has('is_active')
            ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
            : null;
        $roleId  = $request->has('role_id') ? (int) $request->query('role_id') : null;

        $paginator = $this->service->list($search, $perPage, $active, $roleId);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (User $u) => $this->format($u))->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);
        return response()->json($this->format($user));
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        /** @var array{first_name: string, last_name: string, email: string, phone?: string|null, job_title?: string|null, hire_date?: string|null, manager_id?: int|null, is_active?: bool, department_id?: int|null, security_level_id?: int, extra_permissions?: list<string>, notes?: string|null, roles: list<string>} $validated */
        $validated = $request->validated();

        [$actorLevel, $actorHasSuperadmin] = $this->actorContext($request);

        try {
            $user = $this->service->create($validated, $actorLevel, $actorHasSuperadmin);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($this->format($user), 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);

        /** @var array{first_name?: string, last_name?: string, email?: string, phone?: string|null, job_title?: string|null, hire_date?: string|null, manager_id?: int|null, department_id?: int|null, security_level_id?: int, extra_permissions?: list<string>, notes?: string|null, roles?: list<string>} $validated */
        $validated = $request->validated();

        [$actorLevel, $actorHasSuperadmin] = $this->actorContext($request);

        try {
            $user = $this->service->update($user, $validated, $actorLevel, $actorHasSuperadmin);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($this->format($user));
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $user   = $this->service->findOrFail($id);
        $active = (bool) $request->input('is_active', !$user->is_active);

        [$actorLevel, $actorHasSuperadmin] = $this->actorContext($request);

        try {
            $user = $this->service->setActive($user, $active, $actorLevel, $actorHasSuperadmin);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($this->format($user));
    }

    /** Fase 12c — POST /api/users/{id}/activation-link: reenvía el enlace a una cuenta que todavía no eligió su contraseña. */
    public function resendActivation(int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);

        if ($user->is_active !== true || $user->must_change_password !== true) {
            return response()->json(['message' => 'Solo se reenvía el enlace a una cuenta activa que aún no eligió su contraseña.'], 422);
        }

        app(PasswordResetService::class)->sendActivationLink($user);

        return response()->json(['message' => 'Enlace de activación enviado.']);
    }

    /** ADR-006, Decisión 10 — PATCH /api/admin/users/{id}/flags */
    public function updateFlags(Request $request, int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);

        /** @var array{approve_large_amounts?: bool, manage_users?: bool} $data */
        $data = $request->validate([
            'approve_large_amounts' => ['sometimes', 'boolean'],
            'manage_users'          => ['sometimes', 'boolean'],
        ]);

        [$actorLevel, $actorHasSuperadmin] = $this->actorContext($request);

        try {
            $user = $this->service->updateFlags($user, $data, $actorLevel, $actorHasSuperadmin);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json($this->format($user));
    }

    /** SCRUM-724 — GET /api/admin/users/{id}/module-visibility */
    public function moduleVisibility(int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);

        return response()->json($this->service->getModuleVisibility($user));
    }

    /** SCRUM-724 — PUT /api/admin/users/{id}/module-visibility */
    public function updateModuleVisibility(Request $request, int $id): JsonResponse
    {
        $user = $this->service->findOrFail($id);

        /**
         * @var array{
         *   modules: list<array{module: string, can_view: int, can_view_team: bool}>,
         *   menuItems: list<array{module: string, key: string, visible: bool}>,
         * } $data
         */
        $data = $request->validate([
            'modules'                 => ['required', 'array'],
            'modules.*.module'        => ['required', 'string', Rule::in(ModuleCatalog::keys())],
            'modules.*.can_view'      => ['required', 'integer', Rule::in([RoleModuleVisibility::VIEW_NONE, RoleModuleVisibility::VIEW_READONLY, RoleModuleVisibility::VIEW_FULL])],
            'modules.*.can_view_team' => ['required', 'boolean'],
            'menuItems'                 => ['present', 'array'],
            'menuItems.*.module'        => ['required', 'string', Rule::in(ModuleCatalog::keys())],
            'menuItems.*.key'           => ['required', 'string', 'max:60'],
            'menuItems.*.visible'       => ['required', 'boolean'],
        ]);

        $result = $this->service->updateModuleVisibility($user, $data['modules'], $data['menuItems']);

        return response()->json($result);
    }

    /**
     * SCRUM-739 (UAT-2) — GET /api/admin/module-visibility/bulk. Estado actual de la máscara
     * global de rollout (ver UatVisibilityService) — el frontend lo usa para precargar el
     * checklist del modal con lo que ya está oculto, en vez de arrancar siempre en blanco.
     */
    public function bulkModuleVisibilityState(): JsonResponse
    {
        return response()->json($this->uatVisibility->currentState());
    }

    /**
     * SCRUM-739 (UAT-2) — POST /api/admin/module-visibility/bulk. Oculta/restaura módulos/accesos
     * para TODOS los usuarios no-superadmin de una sola vez, vía una máscara global en
     * system_settings (UatVisibilityService) — a propósito NO usa el override individual de
     * SCRUM-724 (UserModuleVisibility), ver docblock de UatVisibilityService para el porqué
     * (hallazgo real de Pre-QA: un restore masivo ahí podía borrar un override individual
     * preexistente sin relación con UAT). `menuItems.*.module` no está limitado a ModuleCatalog —
     * cubre tanto los botones de Catálogo (bajo `ventas_diseno`) como el ítem sintético
     * `{module: 'configuracion', key: 'root'}` (Configuración no es parte de ModuleCatalog).
     */
    public function bulkModuleVisibility(Request $request): JsonResponse
    {
        /**
         * @var array{
         *   modules: list<string>,
         *   menuItems: list<array{module: string, key: string}>,
         *   action: string,
         * } $data
         */
        $data = $request->validate([
            'modules'            => ['present', 'array'],
            'modules.*'          => ['string', Rule::in(ModuleCatalog::keys())],
            'menuItems'          => ['present', 'array'],
            'menuItems.*.module' => ['required', 'string', 'max:60'],
            'menuItems.*.key'    => ['required', 'string', 'max:60'],
            'action'             => ['required', 'string', Rule::in(['hide', 'restore'])],
        ]);

        /** @var array{sub?: int}|null $jwt */
        $jwt = $request->attributes->get('jwt');

        $result = $this->uatVisibility->apply(
            (int) ($jwt['sub'] ?? 0),
            $data['modules'],
            $data['menuItems'],
            $data['action'] === 'hide',
        );

        return response()->json($result);
    }

    /** @return array<string, mixed> */
    private function format(User $user): array
    {
        $dept    = $user->department;
        $level   = $user->securityLevel;
        $manager = $user->manager;

        return [
            'id'               => $user->id,
            'first_name'       => $user->first_name,
            'last_name'        => $user->last_name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            // SCRUM-822 (REQ-548) — cargo/fecha de ingreso/jefe directo.
            'job_title'        => $user->job_title,
            'hire_date'        => $user->hire_date?->toDateString(),
            'manager'          => $manager ? ['id' => $manager->id, 'name' => $manager->first_name . ' ' . $manager->last_name] : null,
            'is_active'        => $user->is_active,
            'mfa_enabled'      => $user->mfa_enabled,
            // Fase 12c — cuenta activa que aún no eligió su contraseña: la lista ofrece «Reenviar enlace».
            'pending_activation' => $user->is_active === true && $user->must_change_password === true,
            'notes'            => $user->notes,
            'extra_permissions' => $user->extra_permissions,
            'department'       => $dept  ? ['id' => $dept->id,  'name' => $dept->name]  : null,
            'security_level'   => $level ? ['id' => $level->id, 'level' => $level->level, 'name' => $level->name] : null,
            'roles'            => $user->roles(),
            // ADR-006, Decisión 1 revisada — rol base (SCRUM-144) + roles adicionales
            // (exclusivos de superadmin, ver UserService).
            'role_id'            => $user->role_id,
            'additional_role_ids' => $user->additionalRoles->pluck('role_id')->values()->all(),
            // ADR-006, Decisión 8/10 — flags transversales por persona.
            'approve_large_amounts' => $user->approve_large_amounts,
            'manage_users'          => $user->manage_users,
        ];
    }

    /** @return array{int|null, bool} */
    private function actorContext(Request $request): array
    {
        $payload = $request->attributes->get('jwt');
        if (! is_array($payload) || ! isset($payload['sub'])) {
            return [null, false];
        }

        $permissions  = is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];
        $isSuperadmin = in_array('superadmin.all', $permissions, true);

        // Prefer the security_level claim (added to JWT 2026-06-20) to avoid a DB round-trip.
        // Fall back to a DB lookup for tokens issued before this change.
        if (isset($payload['security_level'])) {
            $actorLevel = (int) $payload['security_level'];
        } else {
            $actor      = User::with('securityLevel')->find((int) $payload['sub']);
            $level      = $actor !== null ? $actor->securityLevel : null;
            $actorLevel = $level !== null ? $level->level : null;
        }

        return [$actorLevel, $isSuperadmin];
    }
}
