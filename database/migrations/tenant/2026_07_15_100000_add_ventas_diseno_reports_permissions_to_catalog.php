<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Grupo 4 (Reportes) — 2 permisos angostos nuevos, mismo patrón que
 * edit.approved/select_partner_price/edit.conditions (grant individual vía
 * extra_permissions, Luis lo asigna a mano, no atado a ningún security_level):
 *
 * - ventas_diseno.reports.configure: editar Meta por vendedor/equipo
 *   (sales_goals, REQ-072/SCRUM-146) y umbral/meses/probabilidades
 *   (report_settings, REQ-075/076) desde la sección "Configuración" de Reportes.
 * - ventas_diseno.commissions.mark_paid: marcar una cohorte mensual de comisión
 *   como pagada (REQ-073). Luis pidió explícitamente (2026-07-08) que este no
 *   quede atado a superadmin sino que se pueda reasignar a otro rol/persona
 *   (ej. Admin & Contab.) sin cambio de código — de ahí el permiso angosto en
 *   vez de un gate `role:superadmin` hardcodeado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            [
                'key' => 'ventas_diseno.reports.configure', 'module' => 'ventas_diseno',
                'label' => 'Configurar Metas y parámetros de Reportes en Ventas & Diseño',
                'sort_order' => 67, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'key' => 'ventas_diseno.commissions.mark_paid', 'module' => 'ventas_diseno',
                'label' => 'Marcar comisiones como pagadas en Ventas & Diseño',
                'sort_order' => 68, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', ['ventas_diseno.reports.configure', 'ventas_diseno.commissions.mark_paid'])
            ->delete();
    }
};
