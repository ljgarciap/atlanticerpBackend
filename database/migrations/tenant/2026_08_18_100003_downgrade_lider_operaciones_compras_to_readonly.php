<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-771 (corrección 2026-08-18) — reemplaza el `can_view=VIEW_FULL` que la migración
 * 2026_08_18_100001 le dio a `lider_operaciones` en `compras` por `can_view=VIEW_READONLY` (1),
 * el valor reservado de ADR-006 Decisión 2 que hasta ahora nunca se había usado. A partir de este
 * batch, `User::effectivePermissionsV2()`/`modulesPayload()` tratan VIEW_READONLY como "el módulo
 * es visible/navegable (compras.view=true, el Sidebar sigue mostrando la sección) pero NO emite
 * el permiso amplio `compras.read`" — a diferencia de VIEW_FULL, que sigue emitiendo `.read`
 * (y `.edit`/`.approve` según la security_level) sin cambios para todos los demás roles.
 *
 * El acceso real a las 4 pantallas que el ticket sí permite queda cubierto por el permiso angosto
 * `compras.limited.view` (migración 2026_08_18_100002 + `SpecialPermissionSeeder`), no por este
 * `can_view`. Sin esa combinación, VIEW_READONLY por sí solo dejaría a este rol viendo la sección
 * Compras del sidebar pero sin poder abrir ninguna pantalla — es intencional, evita que alguien
 * reintroduzca sin querer `compras.read` amplio en el futuro.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEY = 'lider_operaciones';

    public function up(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            throw new \RuntimeException('Rol [lider_operaciones] no encontrado.');
        }

        DB::table($this->table('role_module_visibility'))->updateOrInsert(
            ['role_id' => $roleId, 'module' => 'compras'],
            ['can_view' => 1, 'can_view_team' => false], // 1 = VIEW_READONLY (ver RoleModuleVisibility)
        );
    }

    public function down(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            return;
        }

        DB::table($this->table('role_module_visibility'))->updateOrInsert(
            ['role_id' => $roleId, 'module' => 'compras'],
            ['can_view' => 2, 'can_view_team' => false], // vuelve al VIEW_FULL de 2026_08_18_100001
        );
    }
};
