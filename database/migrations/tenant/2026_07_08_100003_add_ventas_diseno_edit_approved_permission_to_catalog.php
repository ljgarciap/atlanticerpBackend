<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso elevado y angosto para REQ-008 (restricciones de edición por etapa):
 * solo quien tenga este permiso puede editar una tarjeta en etapa "approved".
 * No se asigna a ningún security_level en esta migración — Luis lo asignará vía
 * extra_permissions al usuario "Mark" cuando se ajusten los permisos del módulo
 * (ver memory/project_backlog_ventas_diseno_req.md, decisión 2026-07-07: el
 * permiso elevado de Mark no va en la base del rol Gerencia, va como grant
 * individual).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key'        => 'ventas_diseno.edit.approved',
            'label'      => 'Editar tarjetas Aprobadas en Ventas & Diseño',
            'module'     => 'ventas_diseno',
            'sort_order' => 64,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'ventas_diseno.edit.approved')
            ->delete();
    }
};
