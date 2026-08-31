<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * Grants de `extra_permissions` angostos, concebidos para una persona puntual (no un rol
 * completo) — para cuando un permiso no pertenece a un `security_level` ni a un rol entero, sino
 * a un individuo específico por una excepción puntual (ver docs/adr/006... para el resto del
 * esquema de permisos). Corre en TODOS los entornos (bloque "Tipo A" de DatabaseSeeder).
 *
 * Idempotente por fusión, no por reemplazo: cada corrida UNE estos permisos a lo que la persona ya
 * tenga en `extra_permissions` (nunca lo pisa) — así un `db:seed --force` en cada deploy no
 * deshace un grant adicional que un superadmin haya asignado a mano después vía la pantalla de
 * administración.
 *
 * Scaffolding: este archivo se genericizó (sesión 2026-08-30, Atlantic/CLAUDE.md) — el `GRANTS`
 * original traía permisos reales atados a personas reales de un cliente anterior. Se vació a
 * propósito; queda el mecanismo como ejemplo. Al vaciarlo, la suite reveló que 3 de esos grants NO
 * eran una excepción de persona (uno lo tenían LOS 10 usuarios de un mismo rol) — capacidad de rol
 * completo mal ubicada acá en vez de en `security_level` (mismo error de fondo que motivó la
 * Fase 2 del esquema de permisos); se migraron a su nivel correspondiente en
 * `2026_08_30_100004_migrate_role_wide_grants_off_special_permission_seeder.php`. Antes de
 * agregar un permiso acá: si lo va a tener cualquiera que ocupe un rol (no una persona puntual
 * nombrada por un ticket), va en `security_level`, no acá — ver esa migración como ejemplo de
 * dónde migrar si se descubre lo mismo de nuevo. Al agregar un permiso angosto real de este tipo,
 * usar el email genérico correspondiente (ver CoreUserSeeder/BusinessRoleUserSeeder) o el email
 * real de la persona una vez que este scaffold tenga datos de un cliente real.
 */
class SpecialPermissionSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     *                                  Ejemplo (comentado): 'gerencia@test.com' => ['ventas_diseno.override_min_margin'],
     */
    protected const GRANTS = [];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command?->warn('Tenant "atlantic" not found — SpecialPermissionSeeder skipped.');

            return;
        }

        $tenant->makeCurrent();

        try {
            foreach (static::GRANTS as $email => $permissions) {
                $user = User::where('email', $email)->first();
                if ($user === null) {
                    $this->command?->warn("Usuario \"{$email}\" no encontrado — grant especial saltado.");

                    continue;
                }

                $merged = array_values(array_unique(array_merge($user->extra_permissions ?? [], $permissions)));
                sort($merged);
                $current = $user->extra_permissions ?? [];
                sort($current);

                if ($merged !== $current) {
                    $user->update(['extra_permissions' => $merged]);
                }
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
