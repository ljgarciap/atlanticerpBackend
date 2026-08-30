<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-725: excepción de margen mínimo para Mark y David — mismo patrón de grant
 * angosto por persona que ventas_diseno.select_partner_price/edit.conditions, no se
 * asigna a ningún security_level acá (ver SpecialPermissionSeeder). Con este permiso,
 * QuoteController/QuoteItemController dejan de bloquear en 422 los cambios que violan
 * el margen mínimo (PricingSettings::min_margin_percent) y en su lugar avisan y dejan
 * continuar — el resto de los usuarios siguen bloqueados igual que hoy.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'auth';

    public function up(): void
    {
        DB::table($this->table('permission_catalog'))->insert([
            'key'        => 'ventas_diseno.override_min_margin',
            'label'      => 'Guardar cotizaciones por debajo del margen mínimo',
            'module'     => 'ventas_diseno',
            'sort_order' => 66,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table($this->table('permission_catalog'))
            ->where('key', 'ventas_diseno.override_min_margin')
            ->delete();
    }
};
