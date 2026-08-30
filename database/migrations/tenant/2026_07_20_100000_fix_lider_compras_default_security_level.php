<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fix RBAC 2026-07-16 (hallazgo del Analista durante la redefinición de la spec de
 * Compras/Proveedores, aprobado por Luis el mismo día). La migración
 * 2026_07_18_100002_reuse_existing_levels_for_role_defaults.php fijó `lider_compras` en
 * Nivel 1 ("Read Only", `can_edit=false` en todos los módulos) — decisión válida en su
 * momento (2026-07-10), porque el módulo Compras todavía no existía. Ahora que Compras se
 * construyó (rama `sprint2`), Yirena Teng (`gerencia2@atlantic.com.pa`, única persona
 * con este rol, ver `BusinessRoleUserSeeder.php`) necesita poder escribir. Los otros 4 roles
 * "líder de área" (`lider_admin_contab`, `lider_bodega`, `lider_servicios`,
 * `vendedor_disenador`) ya están en Nivel 4 ("Power User", `can_edit=true`,
 * `can_approve=false`) — esa es la referencia correcta para `lider_compras`.
 *
 * Mismo principio que ya sigue 2026_07_18_100002: no se edita el historial de esa
 * migración, se corrige hacia adelante.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEY = 'lider_compras';

    private const OLD_LEVEL = 1;

    private const NEW_LEVEL = 4;

    public function up(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $usersTable  = $this->table('users');

        $oldLevelId = DB::table($levelsTable)->where('level', self::OLD_LEVEL)->value('id');
        $newLevelId = DB::table($levelsTable)->where('level', self::NEW_LEVEL)->value('id');

        if ($oldLevelId === null || $newLevelId === null) {
            return;
        }

        $roleId = DB::table($rolesTable)->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            return;
        }

        // 1) Repuntar el default del rol de Nivel 1 a Nivel 4.
        DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $newLevelId]);

        // 2) Remapear cualquier usuario existente de este rol que todavía esté en el nivel
        //    viejo (genérico por role_id, no por email, para cubrir cualquier entorno).
        DB::table($usersTable)
            ->where('role_id', $roleId)
            ->where('security_level_id', $oldLevelId)
            ->update(['security_level_id' => $newLevelId]);
    }

    public function down(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $usersTable  = $this->table('users');

        $oldLevelId = DB::table($levelsTable)->where('level', self::OLD_LEVEL)->value('id');
        $newLevelId = DB::table($levelsTable)->where('level', self::NEW_LEVEL)->value('id');

        if ($oldLevelId === null || $newLevelId === null) {
            return;
        }

        $roleId = DB::table($rolesTable)->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            return;
        }

        // Revertir usuarios de este rol que quedaron en Nivel 4 por esta migración (huella:
        // coincide con el nuevo default de su rol, mismo criterio que el down() de
        // 2026_07_18_100002).
        DB::table($usersTable)
            ->where('role_id', $roleId)
            ->where('security_level_id', $newLevelId)
            ->update(['security_level_id' => $oldLevelId]);

        DB::table($rolesTable)->where('id', $roleId)->update(['default_security_level_id' => $oldLevelId]);
    }
};
