<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-500 — permiso angosto para editar `bodega_settings` (umbrales configurables del Home de
 * Bodega, ver `BodegaSettingsService`). Mismo patrón que `compras.settings.configure`
 * (2026_07_20_100005): grant individual vía `extra_permissions`, no atado a ningún security_level
 * — `superadmin.all` ya lo bypassa. `bodega.read` (derivado de la matriz de módulos) sigue
 * alcanzando para GET; solo PUT exige este permiso angosto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'bodega.settings.configure', 'module' => 'bodega',
            'label' => 'Configurar parámetros de Bodega (ej. ventana de días "urgente")',
            'sort_order' => 71, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'bodega.settings.configure')
            ->delete();
    }
};
