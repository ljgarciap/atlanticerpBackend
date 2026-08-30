<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 14 de Admin&Cont (SCRUM-575→579, REQ-502) — % y monto de comisión de un vendedor,
 * fijados una sola vez cuando su mes de cierre YA cerró (mes_cierre < mes calendario actual).
 * Mismo patrón que `App\Modules\VentasDiseno\Models\CommissionCohort` /
 * `CommissionService::cohortFor()` (otro módulo, REQ-073) — acá replicado a propósito en vez de
 * reusado, ver docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md §1.
 *
 * El mes EN CURSO nunca tiene fila acá — se calcula en vivo en cada request
 * (`CommissionOrderService::percentForMonth()`), es "provisional" (RN2 REQ-502) hasta que cierre.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('commission_month_snapshots'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendedor_id')->constrained('users');
            $table->date('mes_cierre');
            $table->decimal('total_pedidos', 12, 2);
            $table->decimal('porcentaje', 5, 2);
            $table->decimal('monto_comision', 12, 2);
            $table->timestamp('created_at')->nullable();

            // Nombre explícito — el autogenerado (prefijado con el schema completo del tenant)
            // supera los 63 bytes que Postgres permite y trunca colisionando con otra constraint
            // del mismo schema (ver checklist de pre-flight en CLAUDE.md).
            $table->unique(['vendedor_id', 'mes_cierre'], 'admin_contab_commission_snapshots_vendedor_mes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('commission_month_snapshots'));
    }
};
