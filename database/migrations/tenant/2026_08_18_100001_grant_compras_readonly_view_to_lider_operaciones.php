<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-771. `effectivePermissionsV2()`/`modulesPayload()` (App\Models\User) solo emiten
 * `compras.view`/`compras.read` y muestran la sección Compras en el Sidebar
 * (`canSeeCompras = user.modules.compras.view === true`, sin fallback a permisos sueltos como sí
 * tiene Bodega — ver `Sidebar.tsx:133` vs `:153-154`) si existe una fila de `role_module_visibility`
 * (o `user_module_visibility`) con `can_view >= VIEW_FULL` para ese módulo. Agregar solo
 * `extra_permissions: ['compras.read']` NO alcanza — el Sidebar no lo mostraría (a diferencia del
 * caso de Yirena/`bodega.read`, que sí tiene ese fallback especial en Bodega).
 *
 * Verificado que esto es seguro sin tocar `security_level_module_permissions`: `lider_operaciones`
 * está en Nivel 14 ("Solo Ver", `2026_07_17_100002_seed_security_level_module_permissions_for_roles.php`)
 * — `can_edit=false`/`can_approve=false` en los 7 módulos, YA sembrado para `compras` en esa
 * migración original. Dar visibilidad de `compras` acá no habilita edición: a diferencia del caso
 * de `vendedor_disenador` (2026_08_02_100003), Nivel 14 no está compartido con ningún rol que
 * necesite `can_edit=true` en `compras`, así que no hace falta separar a un nivel nuevo.
 *
 * `can_view_team=false`: el ticket no pide ver el trabajo de otras personas, solo consultar.
 *
 * Restante fuera de esta migración (config vía panel admin, no código — mismo criterio que
 * `user_module_visibility`/`user_module_menu_visibility`, tablas que el proyecto nunca siembra por
 * migración): ocultar del menú de Compras los ítems que este rol NO debe ver (`inicio`,
 * `proveedores`, `nueva_orden`, `agencias`, `pagos`, `reclamos`, `reportes` — catálogo completo en
 * `menuItemCatalog.ts`), dejando visibles `ordenes`, `logistica`, `inventario`, `ingresos`,
 * `sustitutos`. Aplicar igual en Dev/Test/Prod (punto 4.1 del ticket).
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
            ['can_view' => 2, 'can_view_team' => false], // 2 = VIEW_FULL (ver RoleModuleVisibility)
        );
    }

    public function down(): void
    {
        $roleId = DB::table($this->table('roles'))->where('key', self::ROLE_KEY)->value('id');

        if ($roleId === null) {
            return;
        }

        DB::table($this->table('role_module_visibility'))
            ->where('role_id', $roleId)
            ->where('module', 'compras')
            ->delete();
    }
};
