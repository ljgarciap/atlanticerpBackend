<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1128 — dos permisos angostos del flujo "Pasar a Pedido":
 * - `ventas_diseno.pass_to_order`: ejecutar la aprobación. Gerencia lo recibe por rol (lo emite
 *   `User::effectivePermissionsV2()` a quien tiene alcance de equipo en Ventas & Diseño); Félix
 *   por `SpecialPermissionSeeder` (grant por persona, no de rol).
 * - `ventas_diseno.pipeline.read_all`: ver el Pipeline de todo el equipo y el detalle de sus
 *   proyectos en SOLO LECTURA (no desbloquea ninguna ruta de escritura ni el resto del módulo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    private const KEYS = [
        ['key' => 'ventas_diseno.pass_to_order',       'label' => 'Pasar proyectos aprobados a Pedido en Bodega',     'sort_order' => 67],
        ['key' => 'ventas_diseno.pipeline.read_all',   'label' => 'Ver el Pipeline de todo el equipo (solo lectura)', 'sort_order' => 68],
    ];

    public function up(): void
    {
        foreach (self::KEYS as $row) {
            DB::table($this->table('permission_catalog'))->insert($row + [
                'module' => 'ventas_diseno', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->whereIn('key', array_column(self::KEYS, 'key'))
            ->delete();
    }
};
