<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-804 — historial de reagendamientos de un ticket (REQ-260/SCRUM-802 sub-ticket 2). Antes,
 * `TicketService::schedule()` sobreescribía `scheduled_at`/`scheduled_ends_at` sin dejar rastro —
 * este historial es aditivo (nunca se edita/borra una fila), una por cada reagendamiento real
 * (nunca por el PRIMER agendamiento de un ticket sin fecha previa, ver `TicketService::schedule()`
 * — RN de negocio: "motivo obligatorio al reagendar", no al agendar por primera vez).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('ticket_reschedule_history'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained($this->table('tickets'), 'id', 'servicios_reschedule_history_ticket_fk')
                ->cascadeOnDelete();

            $table->timestamp('previous_scheduled_at');
            $table->timestamp('previous_scheduled_ends_at')->nullable();
            $table->timestamp('new_scheduled_at');
            $table->timestamp('new_scheduled_ends_at')->nullable();
            $table->text('motivo');

            $table->foreignId('rescheduled_by')->nullable()
                ->constrained('users', 'id', 'servicios_reschedule_history_user_fk')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_reschedule_history'));
    }
};
