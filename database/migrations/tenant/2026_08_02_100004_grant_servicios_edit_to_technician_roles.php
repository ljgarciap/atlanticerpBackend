<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios (docs/architecture/servicios-fase4-diseno.md, sección 6). `tecnico_servicios`
 * (Tecnico Servicios Test, Tecnico Servicios Test 2, Agustín Rodríguez) y `garantias_servicios` (Garantias Servicios Test)
 * necesitan poder generar/editar el Informe de Inspección y la Cotización de SU ticket asignado
 * (REQ-238 RN5, REQ-292 RN7, batches futuros 8-9/11-12) — hoy están en Nivel 1 ("Read Only",
 * `can_edit=false` en los 7 módulos), asignado cuando Servicios todavía no existía
 * funcionalmente (ver 2026_07_18_100002_reuse_existing_levels_for_role_defaults.php).
 *
 * Mismo patrón exacto que `2026_07_22_100010_grant_bodega_edit_to_operational_roles.php`: en vez
 * de tocar la fila de Nivel 1 directamente (lo que ampliaría la semántica de "Read Only" para
 * CUALQUIER rol futuro que caiga en ese nivel, incl. `asistente_administrativa`/
 * `lider_operaciones`, que hoy también son Nivel 1 pero no deben ganar nada en `servicios`), se
 * reusa un nivel de la escala 1-10 que hoy no es el default de ningún rol (Nivel 5, "Team Lead" —
 * confirmado sin uso: 2,3,4,8,9,10 están tomados por otros roles/Nivel 3 ya lo reusó Bodega,
 * ver 2026_07_22_100010) y se le da `can_edit=true` ÚNICAMENTE en `servicios`, dejando los otros 6
 * módulos sin fila (ausencia de fila = `can_edit=false` por defecto, ver `User::modulesPayload()`
 * — no hace falta sembrar los 6 explícitamente en `false`).
 *
 * No sube su Nivel general (siguen siendo "de solo lectura" en Ventas&Diseño/Compras/Bodega/etc.,
 * ver instrucción del ticket) — la restricción MÁS FINA de RN5/RN7 ("solo SUS tickets asignados",
 * no cualquier ticket) es lógica de negocio del controller/policy de Informe/Cotización (batches
 * futuros), no algo que este modelo de permisos por módulo pueda expresar.
 *
 * Nota — REQ-218 RN7: el cambio de ESTADO del ticket (Resuelto/Cerrado/tablero) es exclusivo de
 * `lider_servicios`/superadmin pese a que estos 2 roles ganan `servicios.edit` acá — esa
 * restricción más angosta se aplica en la ruta (`role:superadmin,lider_servicios`, ver
 * `routes/servicios.php`), no en esta matriz de permisos por módulo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLES = ['tecnico_servicios', 'garantias_servicios'];

    private const OLD_LEVEL = 1;

    private const NEW_LEVEL = 5;

    public function up(): void
    {
        $rolesTable = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable = $this->table('users');

        $oldLevelId = (int) DB::table($levelsTable)->where('level', self::OLD_LEVEL)->value('id');
        $newLevelId = (int) DB::table($levelsTable)->where('level', self::NEW_LEVEL)->value('id');

        // 1) Matriz del Nivel 5: can_edit=true solo en servicios. No se siembran los otros 6
        //    módulos — ausencia de fila ya resuelve a can_edit=false (ver docblock).
        DB::table($matrixTable)->updateOrInsert(
            ['security_level_id' => $newLevelId, 'module' => 'servicios'],
            ['can_edit' => true, 'can_approve' => false],
        );

        foreach (self::ROLES as $roleKey) {
            $roleId = DB::table($rolesTable)->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }

            // 2) Repuntar el default del rol de Nivel 1 a Nivel 5.
            DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $newLevelId]);

            // 3) Remapear usuarios existentes de este rol que todavía estén en el nivel viejo
            //    (genérico por role_id, nunca un override manual — mismo criterio defensivo que
            //    2026_07_22_100010/2026_07_20_100000).
            DB::table($usersTable)
                ->where('role_id', $roleId)
                ->where('security_level_id', $oldLevelId)
                ->update(['security_level_id' => $newLevelId]);
        }
    }

    public function down(): void
    {
        $rolesTable = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable = $this->table('users');

        $oldLevelId = (int) DB::table($levelsTable)->where('level', self::OLD_LEVEL)->value('id');
        $newLevelId = (int) DB::table($levelsTable)->where('level', self::NEW_LEVEL)->value('id');

        foreach (self::ROLES as $roleKey) {
            $roleId = DB::table($rolesTable)->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }

            DB::table($usersTable)
                ->where('role_id', $roleId)
                ->where('security_level_id', $newLevelId)
                ->update(['security_level_id' => $oldLevelId]);

            DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $oldLevelId]);
        }

        DB::table($matrixTable)->where('security_level_id', $newLevelId)->where('module', 'servicios')->delete();
    }
};
