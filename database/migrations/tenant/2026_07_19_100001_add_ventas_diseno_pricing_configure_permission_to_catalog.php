<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-033: permiso angosto para editar pricing_settings desde la sección de
 * Configuración de Cotización — mismo patrón que ventas_diseno.reports.configure
 * (grant individual vía extra_permissions, no atado a ningún security_level,
 * Luis lo asigna a mano a quien corresponda; superadmin.all ya lo bypassa).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'ventas_diseno.pricing.configure', 'module' => 'ventas_diseno',
            'label' => 'Configurar fórmulas de Tipo de Precio en Ventas & Diseño',
            'sort_order' => 69, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'ventas_diseno.pricing.configure')
            ->delete();
    }
};
