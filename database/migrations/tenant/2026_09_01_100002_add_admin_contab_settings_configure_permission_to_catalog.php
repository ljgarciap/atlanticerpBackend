<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-635 (SCRUM-811) — permiso angosto para editar `admin_contab_settings` (umbrales de alertas
 * de fraude, ver `AdminContSettingsService`). Mismo patrón exacto que `bodega.settings.configure`
 * (2026_07_23_100002): grant individual vía `extra_permissions`, no atado a ningún security_level
 * — `superadmin.all` ya lo bypassa. `admin_contab.read` sigue alcanzando para GET; solo PUT exige
 * este permiso angosto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key' => 'admin_contab.settings.configure', 'module' => 'admin_contab',
            'label' => 'Configurar parámetros de Admin&Cont (ej. umbrales de alertas de fraude)',
            'sort_order' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'admin_contab.settings.configure')
            ->delete();
    }
};
