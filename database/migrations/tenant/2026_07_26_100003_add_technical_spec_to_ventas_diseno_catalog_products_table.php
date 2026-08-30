<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-425/426 (REQ-355/356) — ficha técnica del producto: 12 campos técnicos libres (voltage,
 * power, socket_type, color_temperature, luminous_flux, dimensions, weight, material_finish,
 * ip_rating, estimated_lifespan, warranty, certifications), todos string, guardados juntos como
 * un solo JSONB (`technical_spec`) en vez de 12 columnas — no hay necesidad de filtrar/ordenar por
 * ninguno individualmente, y el set completo se lee/escribe siempre en bloque.
 *
 * Nullable a propósito: productos ya existentes en catalog_products no tienen este dato y no hay
 * información real para backfillear — SCRUM-426 (RN2) solo bloquea el ALTA de un producto NUEVO
 * desde Compras (`InventoryController::store()`), nunca reescribe productos ya creados.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->jsonb('technical_spec')->nullable()->after('other_cost');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropColumn('technical_spec');
        });
    }
};
