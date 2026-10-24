<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Seeder;

/**
 * SCRUM-881 (corrección de Daniela, 2026-09-09) — el rol `asistente_administrativa` (Yaneth) tenía
 * `can_view_team = false` para el módulo `admin_contab` en `role_module_visibility` (fila ausente
 * o en su valor por defecto — nunca hubo un seeder que la poblara; esa tabla se edita en vivo desde
 * la pantalla de Roles y Permisos, ver `RoleController`/`RoleService`). `canViewTeamV2('admin_contab')`
 * (`App\Models\User`) hacía exactamente lo que la fila decía: sin `can_view_team`, Yaneth quedaba
 * auto-filtrada a "solo yo misma" en `AdminContInternalCommissionController::buildSummary()` —
 * como si fuera una vendedora más — y en cualquier otro cómputo del módulo que dependa del mismo
 * flag. El código no tenía ningún bug: era la fila de permisos la que le daba a Yaneth un alcance
 * de "solo yo" en vez de "todo el equipo", pese a que el resto de Cobros/Movimientos/Reportes del
 * módulo ya la trataban con acceso completo (su rol ya tenía `can_view = VIEW_FULL`).
 *
 * Corre en TODOS los entornos (bloque "Tipo A" de DatabaseSeeder) — mismo criterio que
 * `SpecialPermissionSeeder`: un hallazgo de permisos real no se resuelve con un grant manual que un
 * reseed revierte. Idempotente — solo escribe si el valor real difiere del esperado, no pisa nada
 * que un superadmin haya vuelto a ajustar distinto vía la pantalla después de este backfill.
 */
class AdminContRoleVisibilityBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'atlantic')->first();

        if ($tenant === null) {
            $this->command->warn('Tenant "atlantic" not found — AdminContRoleVisibilityBackfillSeeder skipped.');
            return;
        }

        $tenant->makeCurrent();

        try {
            $roleId = Role::where('key', 'asistente_administrativa')->value('id');
            if ($roleId === null) {
                $this->command->warn('Rol "asistente_administrativa" no encontrado — backfill saltado.');
                return;
            }

            $row = RoleModuleVisibility::firstOrNew(['role_id' => $roleId, 'module' => 'admin_contab']);
            $row->fill([
                'can_view'      => RoleModuleVisibility::VIEW_FULL,
                'can_view_team' => true,
            ]);
            if ($row->isDirty()) {
                $row->save();
            }
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
