<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Support\PrimaryApprovers;

/**
 * ADR-006, Fase C, Decisión 11 — forma nueva del payload (JWT y el objeto `user` de las
 * respuestas de auth comparten los mismos campos). Antes de esto, `AuthController` y
 * `MfaController` construían el array de claims por separado en 5 sitios distintos
 * (login/refresh/mfa verify) — centralizado acá para que el cutover
 * de v1→v2 (`effectivePermissions()` → `effectivePermissionsV2()`) se aplique en un solo lugar.
 *
 * `role_id` queda `null` para un usuario sin `role_id` asignado — `effectivePermissionsV2()`/
 * `modulesPayload()` ya manejan ese caso cayendo al escape hatch plano (`securityLevel.permissions`
 * + `extra_permissions`), mismo comportamiento que v1 para ese subconjunto (sin acceso a los 7
 * módulos nuevos hasta que un superadmin le asigne un `role_id`, ver ADR-006 Fase D).
 *
 * `role` es la clave del rol base (`roles.key`). Sin fallback al enum legado `user_roles` (retirado en la Fase 2: `role_id` es el único mecanismo).
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
            'role'           => $user->role?->key,
            'role_id'        => $user->role_id,
            'permissions'    => $user->effectivePermissionsV2(),
            'modules'        => $user->modulesPayload(),
            // SCRUM-724 — override de visibilidad de ítems de menú por usuario, capa
            // adicional sobre 'modules'. Ver User::menuVisibilityPayload().
            'menuVisibility' => $user->menuVisibilityPayload(),
            'flags'          => [
                'approve_large_amounts' => (bool) $user->approve_large_amounts,
                'manage_users'          => (bool) $user->manage_users,
                'primary_approver'         => $this->isPrimaryApprover($user),
            ],
            'security_level' => $user->securityLevel?->level,
        ];
    }

    /**
     * SCRUM-1158 («Ver como usuario», ADR-010) — claims de una sesión de impersonación: los del usuario OBJETIVO (`sub`, rol, permisos,
     * módulos, flags: la vista sale idéntica a la suya sin tocar ningún módulo) más `act`, el actor real y la sesión que lo respalda. Quien
     * lee «quién hizo esto» debe usar `act.sub` (ver `AuditService`), nunca `sub`.
     *
     * @return array<string, mixed>
     */
    public function impersonationClaims(User $target, string $tenantSlug, User $actor, string $impersonationId): array
    {
        return [
            ...$this->claims($target, $tenantSlug),
            'act' => ['sub' => $actor->id, 'imp_id' => $impersonationId],
        ];
    }

    /**
     * SCRUM-1157 — identidad, no permiso: ¿es esta persona el aprobador Gerencia (`primary_approver_user_id` de la configuración fiscal)? El middleware
     * `primary_approver_only` ya corta con 403 «Datos de la Empresa» y «Configuración Fiscal» a cualquier otra persona; el flag solo deja que el menú no
     * las ofrezca (superadmin las ve por `superadmin.all`, no por este flag). Es un dato visual: si no se puede resolver NO debe romper el
     * login, así que se reporta el error y devuelve false.
     */
    private function isPrimaryApprover(User $user): bool
    {
        $approverId = rescue(fn () => AdminContFiscalSettings::query()->value('primary_approver_user_id'), null);

        return PrimaryApprovers::isApprover((int) $user->id, $approverId === null ? null : (int) $approverId);
    }


    /**
     * @param array<string, mixed>|null $impersonation bloque `impersonation` (SCRUM-1158) cuando el payload es de una sesión «Ver como usuario».
     * @return array<string, mixed>
     */
    public function userPayload(User $user, string $language, ?array $impersonation = null): array
    {
        $claims = $this->claims($user, tenantSlug: '');

        $payload = [
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
            // Fase 3: nombre visible del rol (`roles.name`, editable por CRUD); `role` es solo la clave. Dato visual, no viaja en el JWT.
            'role_name'       => $user->role?->name,
            'permissions'     => $claims['permissions'],
            'modules'         => $claims['modules'],
            'menuVisibility'  => $claims['menuVisibility'],
            'flags'           => $claims['flags'],
            // Fase 6a — contexto regional del tenant (zona horaria, moneda, país, locale). Dato visual; no viaja en el JWT.
            'region'          => \App\Shared\Multitenancy\Tenant::current()?->region(),
            // Fase 6c — cómo se llama y cómo se captura la identificación fiscal en el país de la empresa (RUC+DV, NIT+DV, EIN).
            'tax_label'       => \App\Shared\Multitenancy\TenantRegion::taxLabel(),
            'fiscal_id'       => \App\Shared\Fiscal\TaxId\TaxIdRulesRegistry::forCountry(\App\Shared\Multitenancy\TenantRegion::country())->describe(),
            // Reset administrativo de contraseña: el frontend fuerza la pantalla de cambio mientras sea true.
            'must_change_password' => $user->must_change_password === true,
        ];

        if ($impersonation !== null) {
            $payload['impersonation'] = $impersonation;
        }

        return $payload;
    }
}
