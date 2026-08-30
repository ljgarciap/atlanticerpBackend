<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Pre-QA 2026-07-22 (CRÍTICO, SCRUM-329 Batch A2) — `asistente_bodega`, `ayudante_general_bodega`
 * y `transporte` tenían `default_security_level_id` en Nivel 1 ("Read Only",
 * `2026_07_18_100002_reuse_existing_levels_for_role_defaults.php`), que en la matriz
 * `security_level_module_permissions` tiene `can_edit = false` para TODOS los módulos, incluido
 * `bodega` — en la práctica, solo `lider_bodega` (Nivel 4) podía operar cualquier paso de
 * escritura del flujo de Pedidos, contradiciendo RN3 de SCRUM-383 ("los Asistentes también pueden
 * asignar el picking").
 *
 * Decisión de Luis 2026-07-22: en vez de crear un Nivel 11+ nuevo (violaría la corrección de
 * ADR-006/2026-07-10 — "los niveles 1-10 YA SON la escala de capacidad, los roles no deben generar
 * niveles nuevos"), reusar el Nivel 3 ("Standard User"), que hoy no lo usa ningún rol ni tiene
 * filas en la matriz (`roles_using_it = 0`, confirmado antes de esta migración) — y darle
 * `can_edit = true` ÚNICAMENTE en `bodega`, dejando los otros 6 módulos en solo-lectura. Evita el
 * over-grant de simplemente subir estos 3 roles a Nivel 4 (que da `can_edit` en los 7 módulos, no
 * solo Bodega).
 *
 * Mismo patrón defensivo que 2026_07_18_100002 para no pisar overrides manuales: los usuarios ya
 * existentes de estos 3 roles solo se remapean si su `security_level_id` actual coincide con el
 * default VIEJO del rol (Nivel 1) — la huella de que vienen del seeder, no de un ajuste manual.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<string> Los 7 módulos existentes en la matriz — ver 2026_07_18_100002. */
    private const MODULES = [
        'ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contab', 'gerencia', 'operaciones',
    ];

    /** @var list<string> */
    private const ROLES = ['asistente_bodega', 'ayudante_general_bodega', 'transporte'];

    public function up(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable  = $this->table('users');

        $level1Id = (int) DB::table($levelsTable)->where('level', 1)->value('id');
        $level3Id = (int) DB::table($levelsTable)->where('level', 3)->value('id');

        // 1) Matriz del Nivel 3: can_edit=true solo en bodega, resto igual que Nivel 1 (solo lectura).
        DB::table($matrixTable)->where('security_level_id', $level3Id)->delete();
        $rows = [];
        foreach (self::MODULES as $module) {
            $rows[] = [
                'security_level_id' => $level3Id,
                'module'            => $module,
                'can_edit'          => $module === 'bodega',
                'can_approve'       => false,
            ];
        }
        DB::table($matrixTable)->insert($rows);

        // 2) Repuntar el default de los 3 roles operativos de Bodega al Nivel 3.
        DB::table($rolesTable)->whereIn('key', self::ROLES)->update(['default_security_level_id' => $level3Id]);

        // 3) Remapear usuarios existentes que todavía apuntan al viejo default (Nivel 1) de estos
        //    3 roles — nunca un override manual (regla del proyecto: no clobbear ajustes puntuales).
        foreach (self::ROLES as $roleKey) {
            $roleId = DB::table($rolesTable)->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }
            DB::table($usersTable)
                ->where('role_id', $roleId)
                ->where('security_level_id', $level1Id)
                ->update(['security_level_id' => $level3Id]);
        }
    }

    public function down(): void
    {
        $rolesTable  = $this->table('roles');
        $levelsTable = $this->table('security_levels');
        $matrixTable = $this->table('security_level_module_permissions');
        $usersTable  = $this->table('users');

        $level1Id = (int) DB::table($levelsTable)->where('level', 1)->value('id');
        $level3Id = (int) DB::table($levelsTable)->where('level', 3)->value('id');

        foreach (self::ROLES as $roleKey) {
            $roleId = DB::table($rolesTable)->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue;
            }
            DB::table($usersTable)
                ->where('role_id', $roleId)
                ->where('security_level_id', $level3Id)
                ->update(['security_level_id' => $level1Id]);
        }

        DB::table($rolesTable)->whereIn('key', self::ROLES)->update(['default_security_level_id' => $level1Id]);

        DB::table($matrixTable)->where('security_level_id', $level3Id)->delete();
    }
};
