<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 5 (REQ-266) — historial de tarifa por día de un técnico externo.
 * Solo consulta (RN2: "no se puede editar ni eliminar entradas pasadas") — sin updated_at, mismo
 * patrón que TicketAttachment (UPDATED_AT = null en el modelo).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('external_technician_rate_history'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_technician_id')
                ->constrained($this->table('external_technicians'), 'id', 'servicios_ext_tech_rate_history_tech_fk')
                ->cascadeOnDelete();

            $table->decimal('tarifa_dia', 10, 2);

            $table->foreignId('changed_by')->nullable()
                ->constrained('users', 'id', 'servicios_ext_tech_rate_history_user_fk')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('external_technician_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('external_technician_rate_history'));
    }
};
