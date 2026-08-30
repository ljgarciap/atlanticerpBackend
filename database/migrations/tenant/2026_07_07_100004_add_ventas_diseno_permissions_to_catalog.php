<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega las 4 llaves de permiso base del módulo "Ventas & Diseño" al catálogo
 * ya existente (auth.permission_catalog) — NO crea tabla nueva, solo inserta filas,
 * igual que hizo el módulo AI/reports en su momento.
 *
 * Estas 4 llaves son solo el gating estructural del módulo (lectura/escritura/edición/
 * borrado), no el modelo de roles/permisos completo — eso queda para los 2 tickets de
 * roles y permisos que va a definir el cliente (ver memory/project_backlog_ventas_diseno_req.md).
 * No se asignan a ningún security_level todavía; eso lo hace un superadmin desde el
 * panel una vez que el modelo de roles esté confirmado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    /** @var list<array{key: string, label: string, sort_order: int}> */
    private array $permissions = [
        ['key' => 'ventas_diseno.read',   'label' => 'Lectura Ventas & Diseño',   'sort_order' => 60],
        ['key' => 'ventas_diseno.write',  'label' => 'Crear en Ventas & Diseño',  'sort_order' => 61],
        ['key' => 'ventas_diseno.edit',   'label' => 'Editar en Ventas & Diseño', 'sort_order' => 62],
        ['key' => 'ventas_diseno.delete', 'label' => 'Eliminar en Ventas & Diseño', 'sort_order' => 63],
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as $permission) {
            DB::table($this->table('permission_catalog'))->insert([
                'key'        => $permission['key'],
                'label'      => $permission['label'],
                'module'     => 'ventas_diseno',
                'sort_order' => $permission['sort_order'],
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', array_column($this->permissions, 'key'))
            ->delete();
    }
};
