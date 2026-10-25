<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\UserAdditionalRole;
use App\Modules\Auth\Support\NoLoginAccounts;
use App\Modules\Auth\Support\RoleKeys;
use App\Shared\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reset administrativo de contraseña (docs/runbooks/reset-password.md). Compartido por el comando
 * `users:reset-password` y la API de superadmin (`POST /api/users/{id}/reset-password` y
 * `POST /api/users/reset-passwords`) para que ambos hagan EXACTAMENTE lo mismo.
 *
 * Qué hace por usuario: contraseña = su propio correo, `must_change_password = true` (el middleware
 * AuthenticateJwt solo deja ver el perfil y cambiar la contraseña hasta que la cambie), revoca todos
 * sus refresh tokens, invalida los access tokens ya emitidos (`password_reset_at`) y deja un evento de auditoría. NUNCA registra ni imprime contraseñas ni hashes.
 * No toca `is_active` ni `security_blocked_at` (un bloqueo de seguridad no se levanta con un reset).
 */
class AdminPasswordResetService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PasswordNotifier $notifier,
        private readonly PasswordResetPolicy $policy,
    ) {}

    /**
     * ¿La cuenta es de máximo privilegio? Mismo criterio exacto que el masivo usa para excluirlas por defecto
     * (`excludeSuperadmins()`): resetear a una de ellas exige re-autenticar al operador (H3).
     */
    public function isTopPrivilege(User $user): bool
    {
        $query = User::query()->where('id', $user->id);
        $this->excludeSuperadmins($query);

        return ! $query->exists();
    }

    /**
     * Revalida contra la BD que el operador SIGUE siendo superadmin (rol base `superadmin`, como el claim `role` del JWT con el que
     * pasó `role:superadmin`) y activo. El JWT no se revalida por sí solo: un token emitido antes de que le quitaran el rol seguiría
     * entrando (H3 de la revisión de seguridad).
     */
    public function operatorIsStillSuperadmin(?User $operator): bool
    {
        if ($operator === null || $operator->is_active !== true) {
            return false;
        }

        $roleKey = $operator->role?->key;

        return $roleKey === RoleKeys::SUPERADMIN;
    }

    /**
     * Usuarios que alcanza un reset masivo: solo ACTIVOS, menos las exclusiones y, por defecto, SIN los superadmin
     * (revisión de seguridad H5: un masivo no debe dejar a todas las cuentas de máximo privilegio con contraseña = correo
     * a la vez; se incluyen solo con `$includeSuperadmins`). Debe haber un tenant activo.
     *
     * @param list<int>    $exceptIds
     * @param list<string> $exceptEmails
     * @return Collection<int, User>
     */
    public function resolveTargets(
        array $exceptIds = [],
        array $exceptEmails = [],
        ?int $excludeUserId = null,
        bool $includeSuperadmins = false,
    ): Collection {
        $query = User::query()->where('is_active', true)->orderBy('id');

        // SCRUM-1142 — las cuentas sin acceso (Tecnico Servicios, Repartidor, Personal de Bodega) nunca entran a un reset masivo: un reset
        // las dejaría con contraseña = correo, es decir, con acceso.
        $query->whereNotIn(DB::raw('lower(email)'), NoLoginAccounts::EMAILS);

        if (! $includeSuperadmins) {
            $this->excludeSuperadmins($query);
        }

        if ($exceptIds !== []) {
            $query->whereNotIn('id', $exceptIds);
        }

        if ($exceptEmails !== []) {
            $query->whereNotIn(DB::raw('lower(email)'), array_map(static fn (string $e): string => mb_strtolower(trim($e)), $exceptEmails));
        }

        if ($excludeUserId !== null) {
            $query->where('id', '<>', $excludeUserId);
        }

        return $query->get();
    }

    /**
     * "Superadmin" para el masivo = CUALQUIER cuenta con bypass total de permisos (SCRUM reset, N1 de la Ronda 2 de
     * Cybersecurity): rol base o adicional con `is_superadmin`, fila legacy `user_roles.role = superadmin`, security
     * level 10 o con `superadmin.all` en sus permisos, o `superadmin.all` en `extra_permissions` (`User::hasPermission()`
     * da bypass total en esos dos últimos casos aunque el rol no sea superadmin).
     *
     * @param \Illuminate\Database\Eloquent\Builder<User> $query
     */
    private function excludeSuperadmins($query): void
    {
        $superRoleIds = Role::query()->where('is_superadmin', true)->pluck('id')->all();

        if ($superRoleIds !== []) {
            $query->where(static function ($q) use ($superRoleIds): void {
                $q->whereNull('role_id')->orWhereNotIn('role_id', $superRoleIds);
            });
            $query->whereNotIn('id', UserAdditionalRole::query()->whereIn('role_id', $superRoleIds)->select('user_id'));
        }

        // Security levels de máximo privilegio: nivel 10 o con `superadmin.all` en sus permisos (la tabla tiene ~10 filas).
        $topLevelIds = SecurityLevel::query()->get(['id', 'level', 'permissions'])
            ->filter(static fn (SecurityLevel $l): bool => $l->level === 10 || in_array('superadmin.all', $l->permissions ?? [], true))
            ->pluck('id')->all();

        if ($topLevelIds !== []) {
            $query->where(static function ($q) use ($topLevelIds): void {
                $q->whereNull('security_level_id')->orWhereNotIn('security_level_id', $topLevelIds);
            });
        }

        // `superadmin.all` suelto en `extra_permissions` (jsonb): `@>` evita el operador `?`, que choca con los bindings de PDO.
        $query->whereRaw("NOT (COALESCE(extra_permissions, '[]'::jsonb) @> '\"superadmin.all\"'::jsonb)");
    }

    /**
     * @param string $operador Quién lo ejecuta (nombre/correo): queda en el evento de auditoría.
     * @param string $origen   'comando' | 'api'
     * @param string $modo     'individual' | 'masivo'
     * @param bool   $reauth   El operador confirmó su contraseña (API): queda en la auditoría (nunca la contraseña)
     *
     * @throws InvalidArgumentException si el usuario está inactivo (un reset no reactiva cuentas).
     */
    public function resetUser(User $user, string $operador, string $origen, string $modo = 'individual', bool $reauth = false): void
    {
        if ($user->is_active !== true) {
            throw new InvalidArgumentException('Usuario inactivo: un reset de contraseña no reactiva cuentas.');
        }

        DB::transaction(function () use ($user, $operador, $origen, $modo, $reauth): void {
            // El cast `hashed` del modelo hashea el correo; el hash nunca sale de aquí ni va a la auditoría
            // (User::auditableAttributes() ya excluye password_hash).
            $user->password_hash = $user->email;
            $user->must_change_password = true;
            // H1: AuthenticateJwt rechaza los access tokens emitidos ANTES de este instante (sesiones previas al reset).
            $user->password_reset_at = now();
            $user->save();

            RefreshToken::where('user_id', $user->id)->update(['revoked' => true]);

            $this->audit->log(
                action: 'auth.user.password_reset',
                entityType: 'user',
                entityId: $user->id,
                metadata: ['operador' => $operador, 'origen' => $origen, 'modo' => $modo, 'reautenticado' => $reauth],
            );
        });

        // Aviso al afectado, FUERA de la transacción: un fallo de envío nunca deshace el reset (ver PasswordNotifier).
        if ($user->password_reset_at !== null) {
            $this->notifier->notifyAdminReset($user, $this->policy->expiresAt($user->password_reset_at));
        }
    }

    /**
     * Resetea a todos los usuarios dados y deja UNA entrada resumen. Devuelve cuántos se resetearon.
     *
     * @param Collection<int, User> $users
     * @param array<string, mixed>  $filtros Parámetros usados para resolver el conjunto (solo para el resumen).
     */
    public function resetMany(Collection $users, string $operador, string $origen, array $filtros = [], bool $reauth = false): int
    {
        $count = 0;
        foreach ($users as $user) {
            $this->resetUser($user, $operador, $origen, 'masivo', $reauth);
            $count++;
        }

        $this->audit->log(
            action: 'auth.user.password_reset_bulk',
            entityType: 'user',
            metadata: ['operador' => $operador, 'origen' => $origen, 'count' => $count, 'filtros' => $filtros, 'reautenticado' => $reauth],
        );

        return $count;
    }
}
