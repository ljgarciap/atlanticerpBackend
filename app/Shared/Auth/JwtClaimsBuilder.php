<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Models\User;

/**
 * ADR-006, Fase C, Decisión 11 — forma nueva del payload (JWT y el objeto `user` de las
 * respuestas de auth comparten los mismos campos). Antes de esto, `AuthController` y
 * `MfaController` construían el array de claims por separado en 5 sitios distintos
 * (login/refresh/select-role/switch-role/mfa verify) — centralizado acá para que el cutover
 * de v1→v2 (`effectivePermissions()` → `effectivePermissionsV2()`) se aplique en un solo lugar.
 *
 * `role_id` queda `null` para un usuario sin `role_id` asignado — `effectivePermissionsV2()`/
 * `modulesPayload()` ya manejan ese caso cayendo al escape hatch plano (`securityLevel.permissions`
 * + `extra_permissions`), mismo comportamiento que v1 para ese subconjunto (sin acceso a los 7
 * módulos nuevos hasta que un superadmin le asigne un `role_id`, ver ADR-006 Fase D).
 *
 * `role` (el claim string, el que sí sigue leyendo `routes/crm.php` vía `EnforceRole`) cae al
 * rol legado (`user_roles`) cuando no hay `role_id` — encontrado en Senior Review 2026-07-10:
 * `UserService::approve()` (registro público) solo asigna `user_roles`, nunca `role_id`, así que
 * sin este fallback cualquier usuario aprobado con rol `management` a partir de hoy perdía el
 * acceso a `GET /api/crm/reports/team`/`DELETE /api/crm/projects/{id}` que sí tenía con v1.
 */
final class JwtClaimsBuilder
{
    /** @return array<string, mixed> */
    public function claims(User $user, string $tenantSlug): array
    {
        return [
            'sub'            => $user->id,
            'tenant'         => $tenantSlug,
            'email'          => $user->email,
            'role'           => $user->role !== null ? $user->role->key : ($user->roles()[0] ?? null),
            'role_id'        => $user->role_id,
            'permissions'    => $user->effectivePermissionsV2(),
            'modules'        => $user->modulesPayload(),
            // SCRUM-724 — override de visibilidad de ítems de menú por usuario, capa
            // adicional sobre 'modules'. Ver User::menuVisibilityPayload().
            'menuVisibility' => $user->menuVisibilityPayload(),
            'flags'          => [
                'approve_large_amounts' => (bool) $user->approve_large_amounts,
                'manage_users'          => (bool) $user->manage_users,
            ],
            'security_level' => $user->securityLevel?->level,
        ];
    }

    /** @return array<string, mixed> */
    public function userPayload(User $user, string $language): array
    {
        $claims = $this->claims($user, tenantSlug: '');

        return [
            'id'              => $user->id,
            'first_name'      => $user->first_name,
            'last_name'       => $user->last_name,
            'email'           => $user->email,
            'phone'           => $user->phone,
            'notes'           => $user->notes,
            'department_id'   => $user->department_id,
            'language'        => $language,
            'security_level'  => $claims['security_level'],
            'role'            => $claims['role'],
            'role_id'         => $claims['role_id'],
            'permissions'     => $claims['permissions'],
            'modules'         => $claims['modules'],
            'menuVisibility'  => $claims['menuVisibility'],
            'flags'           => $claims['flags'],
        ];
    }
}
