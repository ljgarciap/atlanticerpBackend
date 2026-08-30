<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524 RN2) — observación editable por fila del Arqueo
 * del día. `movement_type`/`movement_id` referencian polimórficamente el cobro
 * (`admin_contab.payments`) o la nota de crédito (`admin_contab.credit_notes`) que originó esa
 * fila — sin FK real (2 tablas de origen posibles), la integridad la garantiza la aplicación
 * (`DailyCashCountService`), mismo criterio que el resto de referencias "sueltas" ya usadas en
 * este módulo (ej. `tickets.internal_technician_id`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('daily_cash_count_entries'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_cash_count_id')->constrained($this->table('daily_cash_counts'))->cascadeOnDelete();
            $table->string('movement_type'); // payment | credit_note
            $table->unsignedBigInteger('movement_id');
            $table->text('observacion')->nullable();
            $table->timestamps();

            $table->unique(['daily_cash_count_id', 'movement_type', 'movement_id'], 'daily_cash_count_entries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('daily_cash_count_entries'));
    }
};
