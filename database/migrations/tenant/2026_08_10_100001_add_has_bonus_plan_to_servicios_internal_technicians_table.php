<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 7 (REQ-258 RN7 / REQ-292) — "hoy solo [Carlos Vergara] tiene este
 * plan de bonificación... si en el futuro se define un plan similar para otro técnico, este mismo
 * mecanismo debe poder aplicarse a cualquiera, no solo a Carlos". Modelado como bandera real en
 * vez de hardcodear el nombre en el frontend/backend — evita el anti-patrón que ya señaló
 * feedback_no_hardcoded_business_values.md para otros umbrales de negocio.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('internal_technicians'), function (Blueprint $table): void {
            $table->boolean('has_bonus_plan')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table($this->table('internal_technicians'), function (Blueprint $table): void {
            $table->dropColumn('has_bonus_plan');
        });
    }
};
