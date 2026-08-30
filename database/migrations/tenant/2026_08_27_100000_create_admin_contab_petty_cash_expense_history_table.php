<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 21 (SCRUM-618→623, REQ-541→546) — historial inmutable de rechazos/reaperturas de una
 * línea de Caja Chica. `intentos_rechazo` (Batch 20) ya cuenta CUÁNTAS veces, pero no alcanza para
 * mostrar motivo/quién/cuándo de cada intento, ni para distinguir "nunca rechazada" de "reabierta
 * tras 2 rechazos" cuando el contador vuelve a 0 (ver `AdminContPettyCashExpense::estado()`) — esta
 * tabla es la fuente de verdad de ambas cosas. Sin `updated_at`: es un log de eventos, cada fila es
 * inmutable una vez creada.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('petty_cash_expense_history'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')
                ->constrained($this->table('petty_cash_expenses'), 'id', 'admin_contab_petty_cash_expense_history_expense_fk')
                ->cascadeOnDelete();

            $table->string('accion', 20); // rechazo|reapertura
            $table->text('motivo');

            $table->foreignId('actor_id')->nullable()
                ->constrained('users', 'id', 'admin_contab_petty_cash_expense_history_actor_fk')
                ->nullOnDelete();

            $table->timestampTz('created_at')->useCurrent();

            $table->index('expense_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('petty_cash_expense_history'));
    }
};
