<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 10 (REQ-258 RN2) — "Calidad del trabajo técnico" es, por ahora,
 * captura manual (Aaron/Gerencia) igual que Satisfacción/Puntualidad/Actitud, no una sugerencia
 * automática — ver docblock de `CommissionCalculationService` sobre por qué (REQ-211, la fuente
 * real de la tasa de reincidencia, no existe todavía). Nullable: un mes ya capturado antes de este
 * batch no tiene valor de Calidad hasta que alguien lo edite.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::table($this->table('commission_captures'), function (Blueprint $table): void {
            $table->unsignedTinyInteger('calidad_pct')->nullable()->after('licencia_medica');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('commission_captures'), function (Blueprint $table): void {
            $table->dropColumn('calidad_pct');
        });
    }
};
