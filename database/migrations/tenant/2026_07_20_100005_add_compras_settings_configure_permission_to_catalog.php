<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Permiso angosto para editar compras_settings — mismo patrón que
 * ventas_diseno.reports.configure/ventas_diseno.pricing.configure (grant individual vía
 * extra_permissions, no atado a ningún security_level; superadmin.all ya lo bypassa).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'compras.settings.configure', 'module' => 'compras',
            'label' => 'Configurar parámetros de Compras (ej. umbral de calificación baja)',
            'sort_order' => 70, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'compras.settings.configure')
            ->delete();
    }
};
