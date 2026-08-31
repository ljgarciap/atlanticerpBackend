<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-575/577) — mismo patrón que
 * `2026_08_18_100002_add_compras_limited_view_permission_to_catalog.php` (SCRUM-771):
 * `admin_contab.commissions_internal.view` es el permiso angosto que de verdad habilita la
 * pantalla de Comisiones Internas para un vendedor (`VIEW_READONLY` en `role_module_visibility`
 * deja el módulo navegable pero sin `admin_contab.read`, ver docblock de
 * `2026_08_25_100002_grant_admin_contab_readonly_view_to_vendedor_disenador.php`) — nunca
 * sustituye `admin_contab.read` en el resto de rutas de Admin&Cont (Facturación, Cobros, Notas
 * Crédito, Estado de Cuenta, Cuentas Bancarias siguen exigiendo el permiso completo). Otorgado por
 * persona a los 10 vendedores reales vía `SpecialPermissionSeeder` (mismo criterio que
 * `compras.limited.view` → `lideroperaciones@test.com`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'admin_contab.commissions_internal.view', 'module' => 'admin_contab',
            'label' => 'Ver Comisiones Internas — solo tabla de tramos y propio historial (sin el resto de Admin&Cont)',
            'sort_order' => 75, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'admin_contab.commissions_internal.view')
            ->delete();
    }
};
