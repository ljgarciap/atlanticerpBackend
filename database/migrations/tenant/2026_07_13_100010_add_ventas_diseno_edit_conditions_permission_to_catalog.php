<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-046: el texto de Condiciones de la cotización solo lo puede editar Mark o
 * Felix (líder de Administración) — mismo patrón de grant angosto que
 * ventas_diseno.edit.approved/select_partner_price. No se asigna a ningún
 * security_level acá; Luis lo asigna vía extra_permissions puntualmente a esas
 * 2 cuentas (Felix todavía no tiene usuario real confirmado en el roster —
 * ver memory/project_backlog_roles_permisos_scrum144_145.md).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key'        => 'ventas_diseno.edit.conditions',
            'label'      => 'Editar Condiciones de cotización en Ventas & Diseño',
            'module'     => 'ventas_diseno',
            'sort_order' => 66,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'ventas_diseno.edit.conditions')
            ->delete();
    }
};
