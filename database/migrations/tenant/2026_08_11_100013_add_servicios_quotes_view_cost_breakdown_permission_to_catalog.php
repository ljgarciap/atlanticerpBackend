<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-295 (REQ-232) — desglose de costo real/margen del ítem Subcontratado visible SOLO para
 * Aaron, Mark, David y Whil (personas puntuales, no un rol) — mismo patrón de grant angosto por
 * persona que `ventas_diseno.override_min_margin`/`select_partner_price` (ver
 * SpecialPermissionSeeder, que asigna este permiso a esas 4 cuentas). El resto del módulo Servicios
 * nunca ve `cost_reference`/`margin_percent` de una línea Subcontratado, solo el precio final.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key'        => 'servicios.quotes.view_cost_breakdown',
            'label'      => 'Ver desglose de costo/margen en ítems Subcontratado de Cotización',
            'module'     => 'servicios',
            'sort_order' => 90,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'servicios.quotes.view_cost_breakdown')
            ->delete();
    }
};
