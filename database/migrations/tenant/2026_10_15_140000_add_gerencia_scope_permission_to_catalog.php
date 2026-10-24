<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1150 — `ventas_diseno.gerencia_scope`: la persona con este permiso recibe, dentro de CRM y Ventas & Diseño, el mismo alcance que
 * Gerencia (`management`): ve todos los proyectos, Dashboard CRM, Pedidos, borrar proyectos, reporte de equipo. Se concede por persona
 * (hoy Félix, `SpecialPermissionSeeder`). Sin la fila en el catálogo, guardar al usuario desde la pantalla de usuarios daría 422.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEY = 'ventas_diseno.gerencia_scope';

    public function up(): void
    {
        $table = $this->table('permission_catalog');
        if (DB::table($table)->where('key', self::KEY)->exists()) {
            return;
        }
        DB::table($table)->insert([
            'key' => self::KEY, 'module' => 'ventas_diseno', 'label' => 'Alcance de Gerencia en CRM y Ventas & Diseño',
            'sort_order' => 80, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))->where('key', self::KEY)->delete();
    }
};
