<?php

namespace Database\Seeders;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SecurityLevelSeeder extends Seeder
{
    private const CRM_FULL = ['crm.read', 'crm.write', 'crm.edit', 'crm.delete', 'reports.read', 'reports.export', 'ai.use'];

    /**
     * SCRUM-330 (Fase 4 — Servicios), hallazgo de Senior Review 2026-08-02: `User::plainEscapeHatchPermissions()`
     * mezcla este catálogo legado en `effectivePermissionsV2()` para TODO usuario, tenga o no
     * `role_id` — no es solo el fallback de cuentas legacy que parecía por el nombre del método.
     * Reasignar un rol `role_id`-based (ADR-006) a un Nivel con `security.users`/`settings.global`/
     * `security.levels` en su catálogo le otorga acceso real a esas rutas
     * (`permission:security.users` etc. en `routes/users.php`/`settings.php`/`security-levels.php`),
     * SIN pasar por el flag transversal `manage_users` que es el mecanismo real e intencional para
     * eso (ver `RoleBackfillSeeder`) — pasó con `vendedor_disenador`→Nivel 6 y
     * `tecnico_servicios`/`garantias_servicios`→Nivel 5 al implementar Batch 1 de Servicios.
     * Niveles 8-10 SÍ los necesitan de verdad (Gerencia real vía Nivel 9, ver
     * `SecurityLevelControllerTest`) — no tocar esos. Antes de reasignar CUALQUIER Nivel 1-7 a un
     * rol nuevo, revisar su `permissions` acá primero, no solo la matriz de
     * `SecurityLevelModulePermission` (que es un sistema aparte, ver ADR-006).
     *
     * @var array<int, array{name: string, description: string, permissions: list<string>}>
     */
    private const LEVELS = [
        1  => ['name' => 'Read Only',       'description' => 'View access only. Cannot create, edit or delete any record.',                                    'permissions' => ['crm.read']],
        2  => ['name' => 'Basic User',      'description' => 'Can view and create records. Cannot edit or delete.',                                            'permissions' => ['crm.read', 'crm.write']],
        3  => ['name' => 'Standard User',   'description' => 'Can view, create and edit records. Cannot delete or export.',                                    'permissions' => ['crm.read', 'crm.write', 'crm.edit', 'reports.read']],
        4  => ['name' => 'Power User',      'description' => 'Full record management including export. Cannot manage users.',                                  'permissions' => self::CRM_FULL],
        5  => ['name' => 'Team Lead',       'description' => 'Full record management. No user administration — that is granted per-person via the manage_users flag, not by level.', 'permissions' => self::CRM_FULL],
        6  => ['name' => 'Supervisor',      'description' => 'Full record management. No user administration — that is granted per-person via the manage_users flag, not by level.', 'permissions' => self::CRM_FULL],
        7  => ['name' => 'Manager',         'description' => 'Full record management. No user administration — that is granted per-person via the manage_users flag, not by level.', 'permissions' => self::CRM_FULL],
        8  => ['name' => 'Senior Manager',  'description' => 'Full access to all modules plus global settings management.',                                    'permissions' => [...self::CRM_FULL, 'security.users', 'settings.global']],
        9  => ['name' => 'Admin',           'description' => 'System administrator. All permissions except superadmin-exclusive actions.',                     'permissions' => [...self::CRM_FULL, 'security.users', 'settings.global', 'security.levels']],
        10 => ['name' => 'Super Admin',     'description' => 'Unrestricted access. Exclusive to superadmin role. Can assign any level.',                      'permissions' => [...self::CRM_FULL, 'security.users', 'settings.global', 'security.levels', 'superadmin.all']],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — SecurityLevelSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $table = "{$tenant->slug}_auth.security_levels";
            $now   = now();

            foreach (self::LEVELS as $levelNumber => $data) {
                DB::table($table)->insertOrIgnore([
                    'level'       => $levelNumber,
                    'name'        => $data['name'],
                    'description' => $data['description'],
                    'permissions' => json_encode($data['permissions']),
                    'is_active'   => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
