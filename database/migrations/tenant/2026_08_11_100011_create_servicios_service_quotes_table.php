<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229→234, docs/architecture/servicios-fase4-diseno.md sección
 * 4/8) — Cotización de Servicio. Un ticket puede tener varias cotizaciones a lo largo del tiempo
 * (RN de REQ-234: si la más reciente queda Rechazada, se puede generar una nueva) — por eso NO hay
 * UNIQUE en `ticket_id` (a diferencia de `inspection_reports`/`claim_sheets`, que son 1:1 con el
 * ticket). "La más reciente" se resuelve siempre por `created_at DESC` (`ServiceQuoteService::current()`).
 *
 * `conditions` (condiciones configurables de Gerencia, REQ-237) queda FUERA de este batch a
 * propósito — es Batch 12, no se agrega una columna muerta por adelantado.
 *
 * `numero` se asigna al crear la fila (create-time == "guardar por primera vez", REQ-229 RN2) —
 * nunca queda NULL una vez creada.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('service_quotes'), function (Blueprint $table): void {
            $table->id();
            $table->string('numero')->unique();
            $table->foreignId('ticket_id')
                ->constrained($this->table('tickets'), 'id', 'servicios_service_quotes_ticket_fk')
                ->cascadeOnDelete();
            $table->string('estado')->default('draft'); // draft | sent | approved | rejected

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('itbms', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->text('observations')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('service_quotes'));
    }
};
