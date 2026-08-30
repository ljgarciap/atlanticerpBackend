<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 9 (REQ-278/279, docs/architecture/servicios-fase4-diseno.md sección
 * 8) — Hoja de Reclamo, contraparte de Informe de Inspección para tickets `tipo = claim`
 * (REQ-220 RN1: Reclamos nunca pasa por Informe de Inspección). 1:1 con `Ticket` (`ticket_id`
 * UNIQUE). Sin `numero`/folio a propósito — el diseño aprobado no lo incluye (a diferencia de
 * `inspection_reports.numero`).
 *
 * Sin columnas de snapshot del reclamante/producto: las secciones 1-2 del formulario (datos del
 * reclamante + producto) se precargan en cada lectura desde `tickets`/`ticket_products`, nunca se
 * capturan ni persisten acá (RN de la spec: "nunca se capturan a mano").
 *
 * `estado` bloquea edición total una vez `completed` (RN5) — diverge de `inspection_reports`, que
 * sigue editable después de Completado (ver docblock de `ClaimSheetService::assertCanEdit()`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('claim_sheets'), function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ticket_id')
                ->unique('servicios_claim_sheets_ticket_uq')
                ->constrained($this->table('tickets'), 'id', 'servicios_claim_sheets_ticket_fk')
                ->cascadeOnDelete();

            $table->text('descripcion_problema')->nullable();
            $table->date('fecha_reclamo')->nullable();

            // defectuoso | no_procede | uso_inadecuado | pendiente_evaluacion — obligatorio para
            // completar (RN4), no a nivel de columna (mismo criterio que inspection_reports.conclusion).
            $table->string('diagnostico', 30)->nullable();

            $table->string('firma_responsable')->nullable(); // nombre, no imagen — obligatorio para completar (RN4)

            $table->string('estado', 20)->default('pending'); // pending | completed — RN5: completed = solo lectura total

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('claim_sheets'));
    }
};
