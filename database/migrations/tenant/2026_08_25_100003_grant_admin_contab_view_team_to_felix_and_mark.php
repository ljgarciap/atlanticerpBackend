<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-498 "PERMISOS: Disponible para Felix, Mark y
 * Gerencia") — Comisiones Internas es el primer feature del módulo que realmente consume la
 * distinción `view`/`view_team` de `role_module_visibility` (ADR-006 Fase A/B); el resto de
 * Admin&Cont sigue gateado por el sistema viejo (`permission:admin_contab.read/edit`), así que
 * esta tabla nunca se había terminado de poblar para los roles reales de Admin&Cont:
 *
 * - `lider_admin_contab` (Felix) ya tenía fila para `admin_contab`, pero con `view_team=false` a
 *   propósito — la migración original (2026_07_11_100001) la dejó conservadora porque en ese
 *   momento el cliente no había confirmado explícitamente "todo el equipo" para ningún feature de
 *   este módulo. REQ-498 lo confirma ahora sin ambigüedad. Se actualiza la fila existente.
 * - `management` (Mark) — sin fila para `admin_contab` en absoluto (el `role='gerencia'` ya
 *   sembrado el 2026-07-11 es un role_key distinto, no el de Mark; `CoreUserSeeder` lo registra
 *   como `role='management'`). Sin esta fila, `User::canViewTeamV2('admin_contab')` es `false`
 *   por ausencia — se agrega.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEYS = ['lider_admin_contab', 'management'];

    public function up(): void
    {
        $rolesTable = $this->table('roles');
        $visTable   = $this->table('role_module_visibility');

        foreach (self::ROLE_KEYS as $roleKey) {
            $roleId = DB::table($rolesTable)->where('key', $roleKey)->value('id');
            if ($roleId === null) {
                continue; // rol no sembrado todavía en este entorno — no bloquear el resto
            }

            DB::table($visTable)->updateOrInsert(
                ['role_id' => $roleId, 'module' => 'admin_contab'],
                ['can_view' => 2, 'can_view_team' => true], // 2 = VIEW_FULL (ver RoleModuleVisibility)
            );
        }
    }

    public function down(): void
    {
        $rolesTable = $this->table('roles');
        $visTable   = $this->table('role_module_visibility');

        // Solo revierte `management` (fila nueva) — `lider_admin_contab` vuelve a su valor
        // original conservador (view_team=false), no se borra la fila.
        $roleId = DB::table($rolesTable)->where('key', 'lider_admin_contab')->value('id');
        if ($roleId !== null) {
            DB::table($visTable)->where('role_id', $roleId)->where('module', 'admin_contab')
                ->update(['can_view_team' => false]);
        }

        $mgmtId = DB::table($rolesTable)->where('key', 'management')->value('id');
        if ($mgmtId !== null) {
            DB::table($visTable)->where('role_id', $mgmtId)->where('module', 'admin_contab')->delete();
        }
    }
};
