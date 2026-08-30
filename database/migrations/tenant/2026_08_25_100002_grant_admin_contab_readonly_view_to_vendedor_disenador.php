<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-575/577) — un vendedor puede consultar la tabla de
 * tramos de comisión y su propio historial en Comisiones Internas, sin ver el resto de los
 * vendedores y sin exportar (`view` sin `view_team`, mismo criterio que
 * `CommissionOrderService::summaryForVendor()`/scoping del controller).
 *
 * CORREGIDO en Senior Review (antes de commitear a `dev`) — la primera versión usaba
 * `can_view=VIEW_FULL(2)`, que `User::effectivePermissionsV2()` bridgea a `admin_contab.read` SIN
 * distinción de pantalla — eso desbloqueaba TODO Admin&Cont (Facturación, Cobros, Notas Crédito,
 * Estado de Cuenta, Cuentas Bancarias), no solo Comisiones Internas. Mismo bug, mismo fix, que
 * SCRUM-771 ya encontró y corrigió para `compras.limited.view`
 * (`2026_08_18_100002/100003_*.php`) — acá se usa `VIEW_READONLY(1)` desde el principio: deja el
 * módulo navegable (`.view`, sin `.read`) y el acceso real a Comisiones Internas viene del permiso
 * angosto `admin_contab.commissions_internal.view` (ver
 * `2026_08_25_100004_add_admin_contab_commissions_internal_view_permission.php` +
 * `SpecialPermissionSeeder`), en el OR de esas rutas puntuales (`routes/admin-contab.php`) —
 * nunca sustituye `admin_contab.read` en el resto del módulo.
 *
 * A diferencia de `2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php`, acá NO hace
 * falta re-nivelar nada: ese batch ya sacó a `vendedor_disenador` del Nivel 4 compartido (que
 * traía `can_edit=true` en bloque para los 7 módulos) y lo dejó en un Nivel 6 dedicado, sin fila
 * en `security_level_module_permissions` para `admin_contab` — por default eso ya es
 * `can_edit=false` (`User::modulesPayload()`, sin fila ⇒ false), exactamente lo que este ticket
 * pide. Solo hace falta la fila de `role_module_visibility`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const ROLE_KEY = 'vendedor_disenador';

    public function up(): void
    {
        $rolesTable = $this->table('roles');
        $visTable   = $this->table('role_module_visibility');

        $roleId = DB::table($rolesTable)->where('key', self::ROLE_KEY)->value('id');
        if ($roleId === null) {
            throw new \RuntimeException('Rol [vendedor_disenador] no encontrado.');
        }

        DB::table($visTable)->updateOrInsert(
            ['role_id' => $roleId, 'module' => 'admin_contab'],
            ['can_view' => 1, 'can_view_team' => false], // 1 = VIEW_READONLY (ver RoleModuleVisibility)
        );
    }

    public function down(): void
    {
        $rolesTable = $this->table('roles');
        $visTable   = $this->table('role_module_visibility');

        $roleId = DB::table($rolesTable)->where('key', self::ROLE_KEY)->value('id');
        if ($roleId === null) {
            return;
        }

        DB::table($visTable)->where('role_id', $roleId)->where('module', 'admin_contab')->delete();
    }
};
