<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Mail\WelcomeMail;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\UserAdditionalRole;
use App\Modules\Auth\Models\UserModuleMenuVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Modules\Auth\Support\ModuleCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final class UserService
{
    /** @return LengthAwarePaginator<int, User> */
    public function list(string $search = '', int $perPage = 20, ?bool $active = null, ?int $roleId = null): LengthAwarePaginator
    {
        $query = User::with(['role', 'additionalRoles.role', 'department', 'securityLevel'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name',  'ilike', "%{$search}%")
                  ->orWhere('email',      'ilike', "%{$search}%");
            });
        }

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        if ($roleId !== null) {
            // ADR-006, Fase D — filtro por rol en el listado de usuarios (SCRUM-144).
            // Coincide contra el rol base o cualquier rol adicional (superadmin-only).
            $query->where(function ($q) use ($roleId): void {
                $q->where('role_id', $roleId)
                  ->orWhereHas('additionalRoles', function (Builder $q2) use ($roleId): void {
                      $q2->where('role_id', $roleId);
                  });
            });
        }

        return $query->paginate($perPage);
    }

    public function findOrFail(int $id): User
    {
        return User::with(['role', 'additionalRoles.role', 'department', 'securityLevel'])->findOrFail($id);
    }

    /**
     * Fase D, blocker de Senior Review 2026-07-13 — role_id (rol base) no tenía el mismo gate
     * que additional_role_ids/extra_permissions: un actor sin superadmin.all podía asignar un
     * rol con is_superadmin=true vía role_id y escalar privilegios. Mismo patrón de guardia que
     * el resto de campos sensibles de este servicio.
     *
     * @throws \DomainException if a non-superadmin actor tries to assign a superadmin role as role_id
     */
    private function assertRoleAssignable(?int $roleId, bool $actorHasSuperadmin): void
    {
        if ($roleId === null) {
            return;
        }

        // `guest` es el estado «registro pendiente», no un rol que se le asigne a una persona (Fase 2).
        if (Role::where('id', $roleId)->where('key', Role::KEY_GUEST)->exists()) {
            throw new \DomainException('El rol Invitado es solo el estado de un registro pendiente de aprobación.');
        }

        if ($actorHasSuperadmin) {
            return;
        }

        if (Role::where('id', $roleId)->where('is_superadmin', true)->exists()) {
            throw new \DomainException('Solo un superadmin puede asignar el rol Superadmin como rol base.');
        }
    }

    /**
     * @param array{
     *   first_name: string,
     *   last_name: string,
     *   email: string,
     *   phone?: string|null,
     *   job_title?: string|null,
     *   hire_date?: string|null,
     *   manager_id?: int|null,
     *   department_id?: int|null,
     *   security_level_id?: int,
     *   extra_permissions?: list<string>,
     *   notes?: string|null,
     *   role_id?: int|null,
     *   additional_role_ids?: list<int>,
     *   is_active?: bool,
     * } $data
     * @throws \DomainException if actor's level is not high enough to assign the target security level
     */
    public function create(array $data, ?int $actorLevel = null, bool $actorHasSuperadmin = false): User
    {
        $defaultLevelId = $data['security_level_id']
            ?? SecurityLevel::where('level', 1)->value('id')
            ?? throw new \RuntimeException('Security level 1 (Read Only) not configured.');

        if ($actorLevel !== null && ! $actorHasSuperadmin) {
            $targetLevel = SecurityLevel::where('id', $defaultLevelId)->value('level');
            if ($targetLevel !== null && $targetLevel >= $actorLevel) {
                throw new \DomainException("No puedes crear usuarios en el nivel {$targetLevel} — tu nivel es {$actorLevel}.");
            }
        }

        $this->assertRoleAssignable($data['role_id'] ?? null, $actorHasSuperadmin);

        $user = DB::transaction(function () use ($data, $defaultLevelId, $actorHasSuperadmin): User {
            /** @var User $user */
            $user = User::create([
                'first_name'        => $data['first_name'],
                'last_name'         => $data['last_name'],
                'email'             => $data['email'],
                'password_hash'     => $data['email'],
                'phone'             => $data['phone'] ?? null,
                // SCRUM-822 (REQ-548) — 3 campos del ticket original nunca implementados.
                'job_title'         => $data['job_title'] ?? null,
                'hire_date'         => $data['hire_date'] ?? null,
                'manager_id'        => $data['manager_id'] ?? null,
                'department_id'     => $data['department_id'] ?? null,
                'security_level_id' => $defaultLevelId,
                // ADR-006, Decisión 1 revisada — role_id es el rol base singular, cualquier actor
                // con manage_users puede asignarlo. additional_role_ids (2do rol en adelante) es
                // exclusivo de superadmin — igual que extra_permissions, se ignora en silencio si
                // el actor no lo es.
                'role_id'           => $data['role_id'] ?? null,
                'extra_permissions' => $actorHasSuperadmin ? ($data['extra_permissions'] ?? []) : [],
                'notes'             => $data['notes'] ?? null,
                // SCRUM-822 (REQ-548 RN3) — estado elegible al crear, default true si no se manda
                // (comportamiento ya existente, sin cambios para quien no use el campo nuevo).
                'is_active'         => $data['is_active'] ?? true,
            ]);

            if ($actorHasSuperadmin) {
                foreach (array_unique($data['additional_role_ids'] ?? []) as $roleId) {
                    UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $roleId]);
                }
            }

            return $user->load(['role', 'additionalRoles.role', 'department', 'securityLevel']);
        });

        // SCRUM-822 — sin sentido mandar instrucciones de login a alguien que se creó Inactivo
        // (no puede loguear todavía, ver AuthController::login()). Antes de este ticket, is_active
        // era siempre true al crear, así que esta condición nunca se evaluaba en false.
        if ($user->is_active) {
            Mail::to($user->email)->send(new WelcomeMail(
                firstName:         $user->first_name,
                temporaryPassword: $user->email,
                loginUrl:          (string) config('app.frontend_url', config('app.url')),
            ));
        }

        return $user;
    }

    /**
     * @param array{
     *   first_name?: string,
     *   last_name?: string,
     *   email?: string,
     *   phone?: string|null,
     *   job_title?: string|null,
     *   hire_date?: string|null,
     *   manager_id?: int|null,
     *   department_id?: int|null,
     *   security_level_id?: int,
     *   extra_permissions?: list<string>,
     *   notes?: string|null,
     *   role_id?: int|null,
     *   additional_role_ids?: list<int>,
     * } $data
     * @throws \DomainException if actor's level is not high enough to assign the target security level
     */
    public function update(User $user, array $data, ?int $actorLevel = null, bool $actorHasSuperadmin = false): User
    {
        if ($actorLevel !== null && ! $actorHasSuperadmin) {
            $currentTargetLevel = $user->securityLevel?->level;
            if ($currentTargetLevel !== null && $currentTargetLevel >= $actorLevel) {
                throw new \DomainException("No puedes editar a un usuario de nivel {$currentTargetLevel} — tu nivel es {$actorLevel}.");
            }

            if (isset($data['security_level_id'])) {
                $targetLevel = SecurityLevel::where('id', $data['security_level_id'])->value('level');
                if ($targetLevel !== null && $targetLevel >= $actorLevel) {
                    throw new \DomainException("No puedes asignar el nivel {$targetLevel} — tu nivel es {$actorLevel}.");
                }
            }
        }

        if (array_key_exists('role_id', $data)) {
            $this->assertRoleAssignable($data['role_id'], $actorHasSuperadmin);
        }

        return DB::transaction(function () use ($user, $data, $actorHasSuperadmin): User {
            if (isset($data['first_name'])) {
                $user->first_name = (string) $data['first_name'];
            }
            if (isset($data['last_name'])) {
                $user->last_name = (string) $data['last_name'];
            }
            if (isset($data['email'])) {
                $user->email = (string) $data['email'];
            }
            if (array_key_exists('phone', $data)) {
                $user->phone = $data['phone'] !== null ? (string) $data['phone'] : null;
            }
            // SCRUM-822 (REQ-548) — mismos 3 campos que create().
            if (array_key_exists('job_title', $data)) {
                $user->job_title = $data['job_title'] !== null ? (string) $data['job_title'] : null;
            }
            if (array_key_exists('hire_date', $data)) {
                $user->hire_date = $data['hire_date'] !== null ? \Carbon\Carbon::parse($data['hire_date']) : null;
            }
            if (array_key_exists('manager_id', $data)) {
                $user->manager_id = $data['manager_id'] !== null ? (int) $data['manager_id'] : null;
            }
            if (array_key_exists('department_id', $data)) {
                $user->department_id = $data['department_id'] !== null ? (int) $data['department_id'] : null;
            }
            if (isset($data['security_level_id'])) {
                $user->security_level_id = (int) $data['security_level_id'];
            }
            if (array_key_exists('extra_permissions', $data) && $actorHasSuperadmin) {
                $user->extra_permissions = $data['extra_permissions'] ?? [];
            }
            // ADR-006, Decisión 1 revisada — role_id (rol base) es un simple reemplazo, cualquier
            // actor con manage_users puede cambiarlo. Nunca "agrega" un segundo rol — eso solo
            // pasa vía additional_role_ids, exclusivo de superadmin, más abajo.
            if (array_key_exists('role_id', $data)) {
                $user->role_id = $data['role_id'] !== null ? (int) $data['role_id'] : null;
            }
            if (array_key_exists('notes', $data)) {
                $user->notes = $data['notes'] !== null ? (string) $data['notes'] : null;
            }

            $user->save();

            if (array_key_exists('additional_role_ids', $data) && $actorHasSuperadmin) {
                $user->additionalRoles()->delete();
                foreach (array_unique($data['additional_role_ids'] ?? []) as $roleId) {
                    UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $roleId]);
                }
            }

            return $user->load(['role', 'additionalRoles.role', 'department', 'securityLevel']);
        });
    }

    /** @throws \DomainException if actor's level is not high enough to manage this user */
    public function setActive(User $user, bool $active, ?int $actorLevel = null, bool $actorHasSuperadmin = false): User
    {
        if ($actorLevel !== null && ! $actorHasSuperadmin) {
            $targetLevel = $user->securityLevel?->level;
            if ($targetLevel !== null && $targetLevel >= $actorLevel) {
                throw new \DomainException("No puedes cambiar el estado de un usuario de nivel {$targetLevel} — tu nivel es {$actorLevel}.");
            }
        }

        $user->is_active = $active;
        $user->save();
        return $user;
    }

    /**
     * ADR-006, Decisión 8/10 — flags transversales por persona (approve_large_amounts/
     * manage_users), columnas planas sin depender de rol ni nivel. Misma guardia jerárquica que
     * el resto de mutaciones de usuario.
     *
     * @param array{approve_large_amounts?: bool, manage_users?: bool} $data
     * @throws \DomainException if actor's level is not high enough to manage this user
     */
    public function updateFlags(User $user, array $data, ?int $actorLevel = null, bool $actorHasSuperadmin = false): User
    {
        if ($actorLevel !== null && ! $actorHasSuperadmin) {
            $targetLevel = $user->securityLevel?->level;
            if ($targetLevel !== null && $targetLevel >= $actorLevel) {
                throw new \DomainException("No puedes cambiar los flags de un usuario de nivel {$targetLevel} — tu nivel es {$actorLevel}.");
            }
        }

        if (array_key_exists('approve_large_amounts', $data)) {
            $user->approve_large_amounts = $data['approve_large_amounts'];
        }
        if (array_key_exists('manage_users', $data)) {
            $user->manage_users = $data['manage_users'];
        }
        $user->save();

        return $user;
    }

    /**
     * Creates a pending registration (role=guest, is_active=false).
     *
     * @param array{first_name: string, last_name: string, email: string, department_id: int} $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $levelId = SecurityLevel::where('level', 1)->value('id');

            if ($levelId === null) {
                throw new \RuntimeException('Security level 1 (Read Only) not configured.');
            }

            $guestRoleId = Role::where('key', Role::KEY_GUEST)->value('id')
                ?? throw new \RuntimeException('Rol de sistema «guest» no configurado (migración 2026_10_24_500000).');

            /** @var User $user */
            $user = User::create([
                'first_name'        => $data['first_name'],
                'last_name'         => $data['last_name'],
                'email'             => $data['email'],
                'password_hash'     => $data['email'],
                'department_id'     => $data['department_id'],
                'security_level_id' => $levelId,
                'role_id'           => $guestRoleId,
                'is_active'         => false,
            ]);

            return $user;
        });
    }

    /** @return Collection<int, User> */
    public function listPending(): Collection
    {
        return User::with(['role', 'department'])
            ->where('is_active', false)
            ->whereHas('role', function (Builder $q): void {
                $q->where('key', Role::KEY_GUEST);
            })
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Approves a pending registration: activates user, assigns role + security level.
     *
     * @throws \InvalidArgumentException if user is not a pending guest
     */
    public function approve(User $user, int $roleId, int $securityLevelId, bool $actorHasSuperadmin = false): User
    {
        $this->assertRoleAssignable($roleId, $actorHasSuperadmin);

        $approved = DB::transaction(function () use ($user, $roleId, $securityLevelId): User {
            if ($user->is_active || ! $user->isGuest()) {
                throw new \InvalidArgumentException('Only pending guest users can be approved.');
            }

            $user->is_active         = true;
            $user->role_id           = $roleId;
            $user->security_level_id = $securityLevelId;
            // SCRUM-1034 (H7): la contraseña inicial (= correo, ver WelcomeMail) obliga a cambiarla en el primer ingreso. SIN plazo de
            // caducidad a propósito (decisión de Luis, 2026-10-05): `password_reset_at` queda nulo, así que la inicial vale hasta que la
            // persona entre, sea cuando sea; no se puede saber si lo hará en 48 h. (Un reset administrativo sí caduca: es otro caso.)
            $user->must_change_password = true;
            $user->save();

            return $user->load(['role', 'additionalRoles.role', 'department', 'securityLevel']);
        });

        Mail::to($approved->email)->send(new WelcomeMail(
            firstName:         $approved->first_name,
            temporaryPassword: $approved->email,
            loginUrl:          (string) config('app.frontend_url', config('app.url')),
        ));

        return $approved;
    }

    /**
     * Rejects (deletes) a pending registration.
     *
     * @throws \InvalidArgumentException if user is not a pending guest
     */
    public function reject(User $user): void
    {
        if ($user->is_active || ! $user->isGuest()) {
            throw new \InvalidArgumentException('Only pending guest users can be rejected.');
        }

        DB::transaction(function () use ($user): void {
            $user->delete();
        });
    }

    /**
     * SCRUM-724 — visibilidad efectiva de módulos (override si existe, si no el
     * valor que ya le daría el rol) para las 7 llaves del catálogo + los overrides
     * de ítem de menú explícitos (solo los `visible=false`, ver
     * UserModuleMenuVisibility). Devolver siempre las 7 filas de módulo (nunca solo
     * las que tienen override) es lo que le permite al admin de UI mostrar "Guardar
     * sin tocar nada" sin arriesgarse a persistir un None accidental — ver
     * updateModuleVisibility() para la otra mitad de esa garantía.
     *
     * @return array{
     *   modules: list<array{module: string, can_view: int, can_view_team: bool}>,
     *   menuItems: list<array{module: string, key: string, visible: bool}>,
     * }
     */
    public function getModuleVisibility(User $user): array
    {
        $overrides = UserModuleVisibility::where('user_id', $user->id)->get()->keyBy('module');

        $modules = [];
        foreach (ModuleCatalog::keys() as $module) {
            $override = $overrides->get($module);
            $modules[] = $override !== null
                ? ['module' => $module, 'can_view' => $override->can_view, 'can_view_team' => $override->can_view_team]
                : array_merge(['module' => $module], $this->roleDerivedVisibility($user, $module));
        }

        /** @var list<array{module: string, key: string, visible: bool}> $menuItems */
        $menuItems = array_values(UserModuleMenuVisibility::where('user_id', $user->id)
            ->where('visible', false)
            ->get()
            ->map(fn (UserModuleMenuVisibility $r) => ['module' => $r->module, 'key' => $r->menu_item_key, 'visible' => false])
            ->all());

        return ['modules' => $modules, 'menuItems' => $menuItems];
    }

    /**
     * SCRUM-724 — reemplaza el set completo de overrides de este usuario.
     *
     * Módulos: por cada una de las 7 filas (el frontend siempre manda las 7,
     * mismo patrón que RoleController::updateVisibility), si el valor pedido
     * COINCIDE con lo que el rol ya otorgaría, se borra cualquier override
     * existente (vuelve a heredar) en vez de persistir una fila redundante — así
     * abrir el modal y guardar sin cambiar nada nunca dejar a alguien con menos
     * acceso del que ya tenía (el bug que este diseño evita a propósito: guardar
     * sin tocar nada NO debe persistir "None" para los módulos que el admin no
     * marcó, porque el formulario los precarga en None por defecto).
     *
     * Ítems de menú: reemplazo total (delete + insert) — el frontend solo manda
     * los que quedaron ocultos (sparse), así que un ítem que se vuelve a marcar
     * visible simplemente deja de aparecer en el payload y su fila vieja se borra.
     *
     * @param list<array{module: string, can_view: int, can_view_team: bool}> $moduleRows
     * @param list<array{module: string, key: string, visible: bool}> $menuItemRows
     * @return array{
     *   modules: list<array{module: string, can_view: int, can_view_team: bool}>,
     *   menuItems: list<array{module: string, key: string, visible: bool}>,
     * }
     */
    public function updateModuleVisibility(User $user, array $moduleRows, array $menuItemRows): array
    {
        DB::transaction(function () use ($user, $moduleRows, $menuItemRows): void {
            foreach ($moduleRows as $row) {
                $roleDefault = $this->roleDerivedVisibility($user, $row['module']);
                $matchesRoleDefault = $roleDefault['can_view'] === $row['can_view']
                    && $roleDefault['can_view_team'] === $row['can_view_team'];

                if ($matchesRoleDefault) {
                    // SCRUM-1150 — si la fila también fija edit/approve, solo se normaliza la visibilidad; no se borra.
                    $existing = UserModuleVisibility::where('user_id', $user->id)->where('module', $row['module'])->first();
                    if ($existing !== null && ($existing->can_edit !== null || $existing->can_approve !== null)) {
                        $existing->update(['can_view' => $row['can_view'], 'can_view_team' => $row['can_view_team']]);
                    } else {
                        UserModuleVisibility::where('user_id', $user->id)->where('module', $row['module'])->delete();
                    }
                    continue;
                }

                UserModuleVisibility::updateOrCreate(
                    ['user_id' => $user->id, 'module' => $row['module']],
                    ['can_view' => $row['can_view'], 'can_view_team' => $row['can_view_team']],
                );
            }

            UserModuleMenuVisibility::where('user_id', $user->id)->delete();
            foreach ($menuItemRows as $row) {
                UserModuleMenuVisibility::create([
                    'user_id' => $user->id, 'module' => $row['module'],
                    'menu_item_key' => $row['key'], 'visible' => $row['visible'],
                ]);
            }
        });

        return $this->getModuleVisibility($user);
    }

    /**
     * SCRUM-724 — lo que el rol de este usuario ya otorgaría para $module, sin
     * mirar ningún override — el "default" contra el que updateModuleVisibility()
     * compara para decidir si algo es realmente un override o no.
     *
     * @return array{can_view: int, can_view_team: bool}
     */
    private function roleDerivedVisibility(User $user, string $module): array
    {
        $roleIds = $user->roleIds();
        if ($roleIds === []) {
            return ['can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false];
        }

        if ($this->isSuperadmin($user, $roleIds)) {
            return ['can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true];
        }

        /** @var Collection<int, RoleModuleVisibility> $rows */
        $rows = RoleModuleVisibility::whereIn('role_id', $roleIds)->where('module', $module)->get();

        $canView = (int) ($rows->max('can_view') ?? RoleModuleVisibility::VIEW_NONE);
        $canViewTeam = $rows->contains(fn (RoleModuleVisibility $r) => $r->can_view >= RoleModuleVisibility::VIEW_FULL && $r->can_view_team);

        return ['can_view' => $canView, 'can_view_team' => $canViewTeam];
    }

    /** @param list<int> $roleIds */
    private function isSuperadmin(User $user, ?array $roleIds = null): bool
    {
        $roleIds ??= $user->roleIds();
        if ($roleIds === []) {
            return false;
        }

        return Role::whereIn('id', $roleIds)->where('is_superadmin', true)->exists();
    }

}
