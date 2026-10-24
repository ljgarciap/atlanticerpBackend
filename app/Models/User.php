<?php

namespace App\Models;

use App\Modules\Auth\Models\Department;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\SecurityLevelModulePermission;
use App\Modules\Auth\Models\UserAdditionalRole;
use App\Modules\Auth\Models\UserModuleMenuVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Modules\Auth\Models\UserRole;
use App\Modules\Auth\Services\UatVisibilityService;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $password_hash
 * @property bool $must_change_password
 * @property \Illuminate\Support\Carbon|null $password_reset_at Último reset administrativo (AuthenticateJwt invalida tokens anteriores).
 * @property \Illuminate\Support\Carbon|null $password_changed_at Último cambio de contraseña propio (AuthenticateJwt invalida tokens anteriores, SCRUM-1033).
 * @property string|null $phone
 * @property string|null $job_title
 * @property Carbon|null $hire_date
 * @property int|null $manager_id
 * @property int|null $department_id
 * @property int $security_level_id
 * @property int|null $role_id
 * @property bool $approve_large_amounts
 * @property bool $manage_users
 * @property list<string> $extra_permissions
 * @property string|null $notes
 * @property array<string, mixed> $preferences
 * @property bool $is_active
 * @property bool $mfa_enabled
 * @property Carbon|null $security_blocked_at
 * @property Carbon $created_at
 * @property Department|null $department
 * @property SecurityLevel|null $securityLevel
 * @property Role|null $role
 * @property User|null $manager
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsAuditTrail, Notifiable;

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_MANAGEMENT = 'management';

    public const ROLE_DESIGNER = 'designer';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_ELECTRICIAN = 'electrician';

    public const ROLE_GUEST = 'guest';

    /** @var list<string> */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password_hash',
        'must_change_password',
        'phone',
        'job_title',
        'hire_date',
        'manager_id',
        'department_id',
        'security_level_id',
        'role_id',
        'approve_large_amounts',
        'manage_users',
        'extra_permissions',
        'notes',
        'preferences',
        'is_active',
        'mfa_enabled',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'hire_date' => 'date',
            'mfa_enabled' => 'boolean',
            'password_hash' => 'hashed',
            'must_change_password' => 'boolean',
            'password_reset_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'preferences' => 'array',
            'extra_permissions' => 'array',
            'security_blocked_at' => 'datetime',
            'approve_large_amounts' => 'boolean',
            'manage_users' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new RuntimeException(
                'No active tenant. Resolve tenant (Tenant::makeCurrent) before querying users.'
            );
        }

        return "{$tenant->slug}_auth.users";
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * SCRUM-915 — App\Models\User no vive bajo App\Modules\{Módulo}\Models\..., así que
     * LogsAuditTrail::auditModule() (que deriva el prefijo del namespace, segmento 2) resolvería
     * mal ('models' en vez de 'auth'). Override explícito, no se toca el trait compartido.
     */
    protected static function auditModule(): string
    {
        return 'auth';
    }

    /**
     * SCRUM-915 — nunca persistir password_hash/remember_token en audit_logs.old_values/
     * new_values: son secretos, no datos de negocio auditables. No se puede llamar a
     * `parent::auditableAttributes()` acá — LogsAuditTrail es un trait, no una clase base, así que
     * `parent::` no lo resuelve; se replica la lógica del trait (excluir created_at/updated_at)
     * más los 2 campos sensibles.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected static function auditableAttributes(array $attributes): array
    {
        unset(
            $attributes['created_at'],
            $attributes['updated_at'],
            $attributes['password_hash'],
            $attributes['remember_token'],
        );

        return $attributes;
    }

    /** @return HasMany<UserRole, $this> */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values($this->userRoles()->pluck('role')->map(fn (mixed $r) => (string) $r)->all());
    }

    public function hasRole(string $role): bool
    {
        return $this->userRoles()->where('role', $role)->exists();
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * SCRUM-822 (REQ-548 RN2) — null = "Ninguno — Socio Director", el nivel más alto sin jefe asignado.
     *
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return BelongsTo<SecurityLevel, $this> */
    public function securityLevel(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<UserAdditionalRole, $this> */
    public function additionalRoles(): HasMany
    {
        return $this->hasMany(UserAdditionalRole::class);
    }

    /**
     * ADR-006, Decisión 1 revisada (2026-07-09) — rol base (`role_id`) + roles adicionales
     * (exclusivos de superadmin, tabla `user_additional_roles`), sin duplicados. La inmensa
     * mayoría de usuarios tiene exactamente 1 elemento acá — multi-rol es la excepción, no la
     * norma (por eso el gate de superadmin al escribir, ver `UserService`).
     *
     * Fallback a rol legado (KAN-11, 2026-08-31): un usuario sin `role_id` — el camino real de
     * `UserService::approve()` (registro público), que todavía solo asigna `user_roles`, nunca
     * `role_id` (Fase E del ADR sigue pendiente) — antes de la Fase 3 de la auditoría de permisos
     * seguía teniendo acceso vía el `role:` legado de las rutas (`hasRole()`); al migrar esas
     * rutas a `permission:` (que se resuelve por acá), esos usuarios perdían el acceso en silencio.
     * Si no hay `role_id`/roles adicionales, se resuelve el(los) rol(es) legado(s)
     * (`user_roles.role`, strings) contra `roles.key` — solo 'superadmin'/'management' calzan hoy
     * (el resto de `User::ROLE_*` son fixtures de demo de `AuthUserSeeder`, sin equivalente real
     * en el catálogo de 14 roles nuevo, así que no matchean nada y siguen sin acceso, sin cambio
     * de comportamiento para ellos). No toca `UserService::approve()` ni la tabla `user_roles` —
     * decisión explícita de Luis de acotar el fix a este método, ver KAN-11.
     *
     * @return list<int>
     */
    public function roleIds(): array
    {
        $ids = $this->role_id !== null ? [$this->role_id] : [];
        // Propiedad, no método: reusa la relación si ya viene eager-loaded (`UserService::list()`/
        // `findOrFail()` cargan `additionalRoles`) en vez de disparar una query nueva cada vez que
        // se llama — effectivePermissionsV2()/canViewTeamV2()/modulesPayload() la invocan todas.
        $additional = $this->additionalRoles->pluck('role_id')->all();
        $ids = array_values(array_unique(array_merge($ids, $additional)));

        if ($ids !== []) {
            return $ids;
        }

        $legacyRoles = $this->roles();
        if ($legacyRoles === []) {
            return [];
        }

        return array_values(Role::whereIn('key', $legacyRoles)->pluck('id')->map(fn (mixed $id) => (int) $id)->all());
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        $securityLevel = $this->securityLevel;
        $levelPerms = $securityLevel !== null ? $securityLevel->permissions : [];

        return array_values(array_unique(array_merge($levelPerms, $this->extra_permissions ?? [])));
    }

    public function hasPermission(string $permission): bool
    {
        $effective = $this->effectivePermissions();

        return in_array($permission, $effective, true)
            || in_array('superadmin.all', $effective, true);
    }

    /**
     * ADR-006, Decisión 7 — Fase B. Algoritmo nuevo de resolución de permisos, dos ejes:
     * `role` (qué módulos alcanza) + `security_level` (qué puede hacer dentro de lo que alcanza,
     * con el rol como techo — defensa en profundidad). Construido y testeado, pero NO wireado
     * todavía a JWT/middleware (eso es Fase C, cutover atómico coordinado backend+frontend) —
     * effectivePermissions() (v1) sigue siendo la única fuente real hasta entonces.
     *
     * Usuarios sin `role_id` (todos los reales hoy — Fase A no hizo backfill a propósito, ver
     * migración `add_role_fields_to_auth_users_table`) no obtienen ningún `{module}.*` de este
     * eje — caen solo en el escape hatch plano (securityLevel.permissions + extra_permissions),
     * mismo comportamiento que v1 para ese subconjunto.
     *
     * Decisión 1 revisada (2026-07-09) — multi-rol: se hace unión de visibilidad sobre TODOS los
     * roles del usuario (`role_id` + `user_additional_roles`, ver `roleIds()`), no solo el base.
     * La capacidad (`security_level_module_permissions`) no cambia — depende de
     * `security_level_id`, que es por usuario, no por rol.
     *
     * Hallazgo de Senior Review 2026-07-10, resuelto con Luis el mismo día: las rutas reales
     * (ej. `routes/ventas-diseno.php`) siguen exigiendo el vocabulario plano viejo
     * (`{modulo}.read/.write/.edit`) — el algoritmo de arriba generaba solo el vocabulario nuevo
     * (`{modulo}.view/.edit/.approve`), así que tener rol + visibilidad de módulo NO otorgaba
     * acceso real todavía (solo `extra_permissions` por persona lo hacía, contradiciendo el
     * criterio de aceptación de SCRUM-144/145: "sin tocar código"). Por eso `{modulo}.view` emite
     * también `{modulo}.read`, y `{modulo}.edit` (capacidad) emite también `{modulo}.write` —
     * puente de compatibilidad hacia el vocabulario que las rutas ya conocen, sin tocar ninguna
     * ruta. `extra_permissions` sigue funcionando igual, sin cambios, para excepciones por persona.
     *
     * @return list<string>
     */
    public function effectivePermissionsV2(): array
    {
        $roleIds = $this->roleIds();

        if ($roleIds === []) {
            return $this->plainEscapeHatchPermissions();
        }

        /** @var Collection<int, Role> $roles */
        $roles = Role::whereIn('id', $roleIds)->get();

        if ($roles->contains(fn (Role $r) => $r->is_superadmin)) {
            return ['superadmin.all'];
        }

        $perms = [];

        /** @var Collection<int, RoleModuleVisibility> $visibility */
        $visibility = RoleModuleVisibility::whereIn('role_id', $roleIds)
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->get();

        $viewByModule = [];
        foreach ($visibility as $row) {
            $viewByModule[$row->module] = ($viewByModule[$row->module] ?? false) || $row->can_view_team;
        }

        // SCRUM-771 (2026-08-18) — primer uso real de VIEW_READONLY (reservado desde ADR-006,
        // Decisión 2, hasta ahora nunca usado). A diferencia de VIEW_FULL, NO emite `{module}.read`
        // — el módulo queda navegable/visible (`.view`, el Sidebar lo muestra) pero sin el permiso
        // amplio que desbloquearía TODAS las rutas `permission:{module}.read` del módulo. Pensado
        // para roles cuyo acceso real a pantallas puntuales viene de un permiso angosto en
        // `extra_permissions` (ver `compras.limited.view`), no de leer el módulo entero — hallazgo
        // de validación visual: el ocultamiento de `menuVisibility` (SCRUM-724) es solo cosmético,
        // nunca bloqueó la ruta real. Si otro rol del usuario ya da VIEW_FULL sobre el mismo
        // módulo, ese nivel manda (nunca se degrada un módulo que algún rol ya volvió full).
        $readonlyModules = RoleModuleVisibility::whereIn('role_id', $roleIds)
            ->where('can_view', '=', RoleModuleVisibility::VIEW_READONLY)
            ->pluck('module')
            ->all();
        $readonlyModules = array_values(array_diff($readonlyModules, array_keys($viewByModule)));

        // SCRUM-724: mismo override de arriba (applyModuleVisibilityOverrides), pero
        // en la forma de array que usa este método (presencia = visible, valor =
        // view_team) — un override a "None" saca al módulo del array por completo,
        // así que ni siquiera emite `{module}.view`. VIEW_READONLY vía override de
        // usuario individual no está soportado todavía (solo a nivel de rol) — un
        // override explícito siempre gana en FULL o NONE, nunca dejaría un módulo
        // en la lista de readonly.
        /** @var Collection<int, UserModuleVisibility> $moduleOverrides */
        $moduleOverrides = UserModuleVisibility::where('user_id', $this->id)->get();
        foreach ($moduleOverrides as $override) {
            if ($override->can_view >= RoleModuleVisibility::VIEW_FULL) {
                $viewByModule[$override->module] = $override->can_view_team;
                $readonlyModules = array_values(array_diff($readonlyModules, [$override->module]));
            } else {
                unset($viewByModule[$override->module]);
                $readonlyModules = array_values(array_diff($readonlyModules, [$override->module]));
            }
        }

        foreach ($viewByModule as $module => $viewTeam) {
            $perms[] = "{$module}.view";
            $perms[] = "{$module}.read"; // puente de compatibilidad — ver docblock del método
            if ($viewTeam) {
                $perms[] = "{$module}.view_team";
                // SCRUM-1128 — Gerencia (alcance de equipo en Ventas & Diseño) puede pasar un
                // proyecto aprobado a Pedido; Félix lo recibe por grant individual.
                if ($module === 'ventas_diseno') {
                    $perms[] = 'ventas_diseno.pass_to_order';
                }
            }
        }

        foreach ($readonlyModules as $module) {
            $perms[] = "{$module}.view"; // SCRUM-771 — sin `.read`, ver comentario arriba.
        }

        // Capacidades (edit/approve) nunca se evalúan para módulos readonly, independientemente
        // de lo que diga security_level_module_permissions — el nombre "readonly" es la garantía.
        $viewableModules = array_keys($viewByModule);

        /** @var Collection<int, SecurityLevelModulePermission> $capabilities */
        $capabilities = SecurityLevelModulePermission::where('security_level_id', $this->security_level_id)->get();

        foreach ($capabilities as $capability) {
            if (! in_array($capability->module, $viewableModules, true)) {
                continue;
            }
            if ($capability->can_edit) {
                $perms[] = "{$capability->module}.edit";
                $perms[] = "{$capability->module}.write"; // puente de compatibilidad — ver docblock del método
            }
            if ($capability->can_approve) {
                $perms[] = "{$capability->module}.approve";
            }
        }

        // SCRUM-1150 — override por usuario de edit/approve (null = hereda del nivel); solo en módulos visibles.
        foreach ($moduleOverrides as $override) {
            if (! in_array($override->module, $viewableModules, true)) {
                continue;
            }
            if ($override->can_edit === true) {
                $perms[] = "{$override->module}.edit";
                $perms[] = "{$override->module}.write";
            } elseif ($override->can_edit === false) {
                $perms = array_values(array_diff($perms, ["{$override->module}.edit", "{$override->module}.write"]));
            }
            if ($override->can_approve === true) {
                $perms[] = "{$override->module}.approve";
            } elseif ($override->can_approve === false) {
                $perms = array_values(array_diff($perms, ["{$override->module}.approve"]));
            }
        }

        return array_values(array_unique(array_merge($perms, $this->plainEscapeHatchPermissions())));
    }

    /** @return list<string> */
    private function plainEscapeHatchPermissions(): array
    {
        $securityLevel = $this->securityLevel;
        $perms = $securityLevel !== null ? $securityLevel->permissions : [];
        $perms = array_merge($perms, $this->extra_permissions ?? []);

        if ($this->approve_large_amounts) {
            $perms[] = 'flag.approve_large_amounts';
        }
        if ($this->manage_users) {
            $perms[] = 'flag.manage_users';
            // Puente de compatibilidad (SCRUM-145, hallazgo Pre-QA 2026-07-16): "Gestionar
            // usuarios" es un flag transversal independiente del rol, pero la administración
            // de usuarios (routes/users.php) sigue gateada por el permiso legado security.users
            // — sin este puente, activar el flag no otorgaba ninguna capacidad real.
            $perms[] = 'security.users';
        }

        return array_values(array_unique($perms));
    }

    /**
     * ADR-006, Decisión 2/Riesgo 1 — Fase B. Reemplazo listo para el patrón
     * `hasRole(MANAGEMENT) || hasRole(SUPERADMIN)` repetido 16+ veces (CRM + Ventas & Diseño) —
     * no se sustituye ningún call-site todavía (eso es Fase C), solo se deja el helper construido
     * y testeado.
     *
     * Decisión 1 revisada (2026-07-09) — true si CUALQUIERA de los roles del usuario (base +
     * adicionales) da alcance de equipo en ese módulo.
     */
    public function canViewTeamV2(string $module): bool
    {
        $roleIds = $this->roleIds();
        if ($roleIds === []) {
            return false;
        }

        if (Role::whereIn('id', $roleIds)->where('is_superadmin', true)->exists()) {
            return true;
        }

        // SCRUM-724: un override de user_module_visibility para este módulo
        // reemplaza por completo el valor de rol, en cualquier dirección.
        $override = UserModuleVisibility::where('user_id', $this->id)->where('module', $module)->first();
        if ($override !== null) {
            return $override->can_view >= RoleModuleVisibility::VIEW_FULL && $override->can_view_team;
        }

        return RoleModuleVisibility::whereIn('role_id', $roleIds)
            ->where('module', $module)
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->where('can_view_team', true)
            ->exists();
    }

    /**
     * ADR-006, Decisión 10 — Fase B. Forma del campo `modules` del JWT nuevo (todavía sin emitir,
     * ver Fase C). Siempre incluye las 7 llaves del catálogo, en `false` por defecto para que el
     * frontend no tenga que manejar claves ausentes.
     *
     * @return array<string, array{view: bool, view_team: bool, edit: bool, approve: bool}>
     */
    public function modulesPayload(): array
    {
        $payload = [];
        foreach (ModuleCatalog::keys() as $module) {
            $payload[$module] = ['view' => false, 'view_team' => false, 'edit' => false, 'approve' => false];
        }

        $roleIds = $this->roleIds();
        if ($roleIds === []) {
            return $payload;
        }

        if (Role::whereIn('id', $roleIds)->where('is_superadmin', true)->exists()) {
            foreach (ModuleCatalog::keys() as $module) {
                $payload[$module] = ['view' => true, 'view_team' => true, 'edit' => true, 'approve' => true];
            }

            return $payload;
        }

        // Decisión 1 revisada (2026-07-09) — unión sobre todos los roles del usuario: "view" y
        // "view_team" quedan en true si CUALQUIER rol lo otorga (OR, no el último que se procese).
        /** @var Collection<int, RoleModuleVisibility> $visibility */
        $visibility = RoleModuleVisibility::whereIn('role_id', $roleIds)
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->get();

        foreach ($visibility as $row) {
            if (! array_key_exists($row->module, $payload)) {
                continue;
            }
            $payload[$row->module]['view'] = true;
            $payload[$row->module]['view_team'] = $payload[$row->module]['view_team'] || $row->can_view_team;
        }

        // SCRUM-771 (2026-08-18) — VIEW_READONLY (ver docblock de effectivePermissionsV2()): el
        // módulo queda `view=true` (el Sidebar lo muestra, `canSeeXxx` sigue funcionando) pero
        // nunca entra a la lista de `$readonlyModules` que el loop de capacidades de abajo
        // excluye a propósito — edit/approve quedan en `false` sin importar security_level.
        $readonlyModules = RoleModuleVisibility::whereIn('role_id', $roleIds)
            ->where('can_view', '=', RoleModuleVisibility::VIEW_READONLY)
            ->pluck('module')
            ->all();
        foreach ($readonlyModules as $module) {
            if (array_key_exists($module, $payload) && ! $payload[$module]['view']) {
                $payload[$module]['view'] = true;
            }
        }

        // SCRUM-724: override por usuario, por encima de lo que el rol ya otorga —
        // reemplaza view/view_team del módulo entero, nunca los combina con el
        // valor de rol (a diferencia de la unión OR de arriba entre roles múltiples).
        // No toca edit/approve — esos siguen siendo pura capacidad de security_level,
        // igual que para cualquier otro usuario con ese módulo visible. VIEW_READONLY vía
        // override de usuario individual no está soportado todavía (solo a nivel de rol).
        $this->applyModuleVisibilityOverrides($payload);

        // SCRUM-739 (UAT-2) — máscara global de rollout, nunca escribe en
        // user_module_visibility (a diferencia de SCRUM-724, que es intención
        // individual de un admin) — ver UatVisibilityService. Se aplica DESPUÉS
        // del override individual, a propósito: nunca modifica ni borra ese dato,
        // solo lo esconde de este payload calculado en el momento. edit/approve
        // se apagan solos más abajo porque el loop de capacidades ya salta
        // cualquier módulo con view=false.
        foreach (app(UatVisibilityService::class)->hiddenModules() as $hiddenModule) {
            if (array_key_exists($hiddenModule, $payload)) {
                $payload[$hiddenModule]['view'] = false;
                $payload[$hiddenModule]['view_team'] = false;
            }
        }

        /** @var Collection<int, SecurityLevelModulePermission> $capabilities */
        $capabilities = SecurityLevelModulePermission::where('security_level_id', $this->security_level_id)->get();

        foreach ($capabilities as $capability) {
            if (! ($payload[$capability->module]['view'] ?? false)) {
                continue;
            }
            // SCRUM-771 — readonly nunca gana edit/approve, sin importar security_level_module_permissions.
            if (in_array($capability->module, $readonlyModules, true)) {
                continue;
            }
            $payload[$capability->module]['edit'] = $capability->can_edit;
            $payload[$capability->module]['approve'] = $capability->can_approve;
        }

        // SCRUM-1150 — override por usuario de edit/approve (null = hereda del nivel); solo en módulos visibles y no readonly.
        foreach (UserModuleVisibility::where('user_id', $this->id)->get() as $override) {
            if (! ($payload[$override->module]['view'] ?? false) || in_array($override->module, $readonlyModules, true)) {
                continue;
            }
            if ($override->can_edit !== null) {
                $payload[$override->module]['edit'] = $override->can_edit;
            }
            if ($override->can_approve !== null) {
                $payload[$override->module]['approve'] = $override->can_approve;
            }
        }

        return $payload;
    }

    /**
     * SCRUM-724 — muta $payload in-place aplicando los overrides de
     * user_module_visibility de este usuario. Extraído de modulesPayload() para
     * reusarlo tal cual desde effectivePermissionsV2() (mismo eje view/view_team,
     * forma de array distinta).
     *
     * @param  array<string, array{view: bool, view_team: bool, edit: bool, approve: bool}>  $payload
     */
    private function applyModuleVisibilityOverrides(array &$payload): void
    {
        /** @var Collection<int, UserModuleVisibility> $overrides */
        $overrides = UserModuleVisibility::where('user_id', $this->id)->get();

        foreach ($overrides as $override) {
            if (! array_key_exists($override->module, $payload)) {
                continue;
            }
            $payload[$override->module]['view'] = $override->can_view >= RoleModuleVisibility::VIEW_FULL;
            $payload[$override->module]['view_team'] = $payload[$override->module]['view'] && $override->can_view_team;
        }
    }

    /**
     * SCRUM-724 — segunda capa de UserModuleVisibility: qué módulos y menús ve un
     * usuario puntual, más allá de su rol. Solo `false` explícito oculta un ítem;
     * un módulo/ítem ausente del array significa "visible por defecto" para que el
     * frontend (Sidebar.tsx) no tenga que distinguir "sin override" de "override a
     * visible".
     *
     * @return array<string, array<string, bool>>
     */
    public function menuVisibilityPayload(): array
    {
        /** @var Collection<int, UserModuleMenuVisibility> $overrides */
        $overrides = UserModuleMenuVisibility::where('user_id', $this->id)->where('visible', false)->get();

        $payload = [];
        foreach ($overrides as $override) {
            $payload[$override->module] ??= [];
            $payload[$override->module][$override->menu_item_key] = false;
        }

        // SCRUM-739 (UAT-2) — mismo criterio que la máscara de módulos en
        // modulesPayload(): nunca escribe en user_module_menu_visibility, nunca
        // aplica a superadmin, se computa encima del override individual sin
        // tocarlo. Cubre tanto los botones de Catálogo (module: 'ventas_diseno')
        // como el ítem sintético {module: 'configuracion', key: 'root'} — ese
        // módulo no es parte de ModuleCatalog (Configuración se gatea por
        // permiso, no por modulesPayload), así que este es su único mecanismo
        // de ocultamiento por rollout.
        if (! $this->isSuperadminForVisibility()) {
            foreach (app(UatVisibilityService::class)->hiddenMenuItems() as $hidden) {
                $payload[$hidden['module']] ??= [];
                $payload[$hidden['module']][$hidden['key']] = false;
            }

            // SCRUM-739 (UAT-2) — hallazgo real de Pre-QA, re-corrida contra el diseño nuevo:
            // modulesPayload() ya apaga 'view' para un módulo oculto por UAT, pero
            // effectivePermissionsV2() (permissions del JWT) es un eje TOTALMENTE separado que
            // nunca pasa por la máscara — a propósito, por diseño (la máscara es cosmética, no
            // debe tocar ningún permiso real de ruta, ver docblock de UatVisibilityService).
            // Sidebar.tsx, para ventas_diseno/bodega/servicios, usa un OR explícito
            // (`modules.X.view === true || usePermission('X.read')`) pensado para el caso de una
            // extra_permission individual sin visibilidad de rol — pero ese mismo OR hace que
            // esos 3 módulos (a diferencia de compras/admin_contab/gerencia/operaciones, que no
            // tienen ese OR) nunca se oculten de verdad vía UAT para un usuario con acceso real
            // por rol, porque el permiso plano sigue presente. Emitimos acá una señal SEPARADA
            // (mismo array que ya consume Sidebar.tsx para 'configuracion'/'root', mismo criterio
            // "ausencia = visible, false explícito = oculto") para que el frontend pueda distinguir
            // "oculto por UAT" de "sin extra_permission" sin tocar el eje de `permissions`.
            foreach (app(UatVisibilityService::class)->hiddenModules() as $hiddenModule) {
                $payload[$hiddenModule] ??= [];
                $payload[$hiddenModule]['__module__'] = false;
            }
        }

        return $payload;
    }

    /** SCRUM-739 — mismo chequeo que modulesPayload(), extraído para reusar acá sin duplicar. */
    private function isSuperadminForVisibility(): bool
    {
        $roleIds = $this->roleIds();

        return $roleIds !== [] && Role::whereIn('id', $roleIds)->where('is_superadmin', true)->exists();
    }

    /**
     * Gate independiente de is_active (ver ADR-004, Epic 11): un bloqueo por anomalía de
     * seguridad no debe mezclarse con la aprobación de registro ni la desactivación manual.
     */
    public function isSecurityBlocked(): bool
    {
        return $this->security_blocked_at !== null;
    }
}
