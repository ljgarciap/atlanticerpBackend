<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios (docs/architecture/servicios-fase4-diseno.md, sección 6). El rol
 * `vendedor_disenador` (10 personas) puede ver el panel de Tickets en modo SOLO LECTURA
 * (REQ-216 RN6, REQ-217 RN6, REQ-279 RN6) — sin `view_team`, sin `edit` (la única excepción es
 * crear tickets nuevos, REQ-245, que queda fuera de este batch, ver reporte final de Backend Dev).
 *
 * Hallazgo propio al implementar (no estaba en el ticket original): simplemente agregar la fila de
 * `role_module_visibility` NO alcanza. `vendedor_disenador` está hoy en Nivel 4 ("Power User"),
 * compartido con `lider_admin_contab`/`lider_bodega`/`lider_servicios`/`lider_compras` — y el Nivel
 * 4 ya tiene `can_edit=true` sembrado EN BLOQUE para los 7 módulos del catálogo
 * (2026_07_18_100002, paso 3), inerte hoy para `vendedor_disenador` solo porque su rol no tenía
 * visibilidad de ningún módulo salvo `ventas_diseno`. En cuanto esta migración le da `can_view` en
 * `servicios`, ese `can_edit=true` de bloque dejaría de ser inerte y le abriría edición real —
 * exactamente lo que el ticket pide evitar. No se puede corregir tocando la fila de Nivel 4 en
 * `servicios` (rompería a `lider_servicios`, que sí necesita editar ahí y también es Nivel 4).
 *
 * Solución — mismo patrón que `2026_08_02_100004_grant_servicios_edit_to_technician_roles.php`
 * (y su precedente, `2026_07_22_100010_grant_bodega_edit_to_operational_roles.php`): sacar a
 * `vendedor_disenador` del Nivel 4 compartido y reusar un nivel de la escala 1-10 sin default hoy
 * (Nivel 6, "Supervisor" — 2/6/7 confirmados libres, 3 y 5 ya reusados por Bodega/Servicios-técnico
 * respectivamente), con una matriz angosta que replica EXACTAMENTE su capacidad efectiva actual
 * (`can_edit=true` solo en `ventas_diseno`, el único módulo que su rol ve hoy) y dejando
 * `servicios` (y el resto) en `can_edit=false` — comportamiento 100% preservado para
 * Ventas&Diseño, solo-lectura nueva en Servicios.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEY = 'vendedor_disenador';

    private const OLD_LEVEL = 4;

    private const NEW_LEVEL = 6;

    public function up(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable  = $this->table('users');
        $visTable    = $this->table('role_module_visibility');

        $oldLevelId = (int) DB::table($levelsTable)->where('level', self::OLD_LEVEL)->value('id');
        $newLevelId = (int) DB::table($levelsTable)->where('level', self::NEW_LEVEL)->value('id');

        // 1) Matriz del Nivel 6: can_edit=true SOLO en ventas_diseno (comportamiento actual de
        //    vendedor_disenador, preservado byte a byte), false en el resto — incl. servicios.
        DB::table($matrixTable)->updateOrInsert(
            ['security_level_id' => $newLevelId, 'module' => 'ventas_diseno'],
            ['can_edit' => true, 'can_approve' => false],
        );
        DB::table($matrixTable)->updateOrInsert(
            ['security_level_id' => $newLevelId, 'module' => 'servicios'],
            ['can_edit' => false, 'can_approve' => false],
        );

        $roleId = DB::table($rolesTable)->where('key', self::ROLE_KEY)->value('id');
        if ($roleId === null) {
            throw new \RuntimeException('Rol [vendedor_disenador] no encontrado.');
        }

        // 2) Repuntar el default del rol de Nivel 4 a Nivel 6.
        DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $newLevelId]);

        // 3) Remapear usuarios existentes de este rol que todavía estén en el nivel viejo
        //    (genérico por role_id, nunca un override manual — mismo criterio defensivo que las
        //    migraciones hermanas de esta misma fecha).
        DB::table($usersTable)
            ->where('role_id', $roleId)
            ->where('security_level_id', $oldLevelId)
            ->update(['security_level_id' => $newLevelId]);

        // 4) Visibilidad nueva: servicios, solo lectura (sin view_team, sin edit).
        DB::table($visTable)->updateOrInsert(
            ['role_id' => $roleId, 'module' => 'servicios'],
            ['can_view' => 2, 'can_view_team' => false], // 2 = VIEW_FULL (ver RoleModuleVisibility)
        );
    }

    public function down(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable  = $this->table('users');
        $visTable    = $this->table('role_module_visibility');

        $oldLevelId = (int) DB::table($levelsTable)->where('level', self::OLD_LEVEL)->value('id');
        $newLevelId = (int) DB::table($levelsTable)->where('level', self::NEW_LEVEL)->value('id');

        $roleId = DB::table($rolesTable)->where('key', self::ROLE_KEY)->value('id');
        if ($roleId === null) {
            return;
        }

        DB::table($visTable)->where('role_id', $roleId)->where('module', 'servicios')->delete();

        DB::table($usersTable)
            ->where('role_id', $roleId)
            ->where('security_level_id', $newLevelId)
            ->update(['security_level_id' => $oldLevelId]);

        DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $oldLevelId]);

        DB::table($matrixTable)->where('security_level_id', $newLevelId)->whereIn('module', ['ventas_diseno', 'servicios'])->delete();
    }
};
