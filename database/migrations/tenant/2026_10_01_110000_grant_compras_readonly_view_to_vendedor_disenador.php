<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1013 — Vendedor/Diseñador debe ver "Órdenes - Logística y Envío" en modo consulta, con la
 * misma configuración que ya tiene Operaciones (`lideroperaciones@test.com`, SCRUM-771).
 *
 * Mismo mecanismo exacto de Operaciones (ver `2026_08_18_100001/100002/100003_*.php`):
 *   1. Esta migración: `role_module_visibility.can_view = VIEW_READONLY (1)` sobre `compras` — el
 *      módulo queda navegable (`compras.view`, el Sidebar lo muestra) pero SIN `compras.read`, así
 *      que ninguna ruta `permission:compras.read` del resto de Compras (Proveedores, Inicio,
 *      Reportes, Agencias, Pagos, Garantías) se abre. Nunca VIEW_FULL: eso emitiría `compras.read`
 *      y desbloquearía todo el módulo (el error que SCRUM-771 ya corrigió una vez).
 *   2. `SpecialPermissionSeeder`: el permiso angosto `compras.limited.view` (ya existe en
 *      `permission_catalog`, migración 2026_08_18_100002) por persona a los 10 vendedores reales —
 *      es lo único que de verdad habilita Ver Órdenes/Logística/Inventario/Ver Registros de
 *      Ingreso, solo lectura (`InventoryController::resolveAccess()` nunca le da `canManage`).
 *
 * Sin edición: `vendedor_disenador` está en un nivel dedicado sin fila de
 * `security_level_module_permissions` para `compras` (ver docblock de
 * `2026_08_25_100002_grant_admin_contab_readonly_view_to_vendedor_disenador.php`), o sea
 * `can_edit=false`/`can_approve=false` por default — no hace falta re-nivelar nada.
 *
 * Solo inserta si el rol NO tiene ya una fila para `compras`: si alguien ya le dio un nivel de
 * visibilidad (ej. desde el panel de administración), esta migración no lo degrada ni lo pisa — el
 * ticket exige no modificar ningún otro permiso existente del rol.
 *
 * Fuera de esta migración (configuración vía panel de administración, no código — mismo criterio
 * que SCRUM-771, `user_module_menu_visibility` nunca se siembra por migración): ocultar del menú de
 * Compras de cada vendedor los ítems que Operaciones tampoco ve (`inicio`, `proveedores`,
 * `nueva_orden`, `agencias`, `pagos`, `reclamos`, `reportes`). Es solo cosmético; el bloqueo real
 * de esas rutas ya lo da el no tener `compras.read`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEY = 'vendedor_disenador';

    public function up(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            throw new \RuntimeException('Rol [vendedor_disenador] no encontrado.');
        }

        $visTable = $this->table('role_module_visibility');

        $exists = DB::table($visTable)->where('role_id', $roleId)->where('module', 'compras')->exists();

        if (! $exists) {
            DB::table($visTable)->insert([
                'role_id' => $roleId, 'module' => 'compras',
                'can_view' => 1, 'can_view_team' => false, // 1 = VIEW_READONLY (ver RoleModuleVisibility)
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            return;
        }

        // Solo revierte la fila exacta que esta migración crea (READONLY, sin vista de equipo): si
        // alguien la cambió después por el panel, ese ajuste manual no se borra.
        DB::table($this->table('role_module_visibility'))
            ->where('role_id', $roleId)
            ->where('module', 'compras')
            ->where('can_view', 1)
            ->where('can_view_team', false)
            ->delete();
    }
};
