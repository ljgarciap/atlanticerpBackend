<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 7 (REQ-292) — captura mensual de los 3 criterios de comisión que el
 * sistema no puede calcular solo (satisfacción, puntualidad, actitud), más la bandera de licencia
 * médica (RN4). Un registro por técnico/mes/año (RN1) — hoy solo Carlos Vergara tiene plan de
 * bonificación activo (REQ-258 RN7), pero el mecanismo es genérico por `internal_technician_id`
 * para no repetir trabajo si mañana se define un plan similar para otro técnico.
 *
 * RN5 — editable incluso después de cerrado el mes, con marca de quién/cuándo: `captured_by` +
 * `created_at` para la captura original, `updated_by` + `updated_at` para la última edición (no
 * un historial completo tipo ledger — la propia tabla es "el estado actual", como el resto de
 * captura mensual en Servicios).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('commission_captures'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_technician_id')
                ->constrained($this->table('internal_technicians'), 'id', 'servicios_commission_captures_tech_fk')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->decimal('satisfaccion_promedio', 2, 1);
            $table->unsignedSmallInteger('incidencias_puntualidad');
            $table->decimal('calificacion_actitud', 2, 1);
            $table->boolean('licencia_medica')->default(false);

            $table->foreignId('captured_by')->nullable()
                ->constrained('users', 'id', 'servicios_commission_captures_captured_by_fk')
                ->nullOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users', 'id', 'servicios_commission_captures_updated_by_fk')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['internal_technician_id', 'year', 'month'],
                'servicios_commission_captures_tech_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('commission_captures'));
    }
};
