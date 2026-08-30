<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-034/067: "Precio Socio" solo lo puede seleccionar el perfil Mark, al crear o
 * editar un Cliente Master (mismo patrón de grant angosto que
 * ventas_diseno.edit.approved — no se asigna a ningún security_level acá, Luis lo
 * asigna vía extra_permissions puntualmente a la cuenta de Mark, ver decisión
 * 2026-07-07 en memory/project_backlog_ventas_diseno_req.md: los permisos elevados de
 * Mark no van en la base del rol Gerencia).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key'        => 'ventas_diseno.select_partner_price',
            'label'      => 'Seleccionar Precio Socio en Ventas & Diseño',
            'module'     => 'ventas_diseno',
            'sort_order' => 65,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'ventas_diseno.select_partner_price')
            ->delete();
    }
};
