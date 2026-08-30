<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238→243, docs/architecture/servicios-fase4-diseno.md sección
 * 4/8) — núcleo del Informe de Inspección. `findings` es un JSONB array de
 * `[{label, value, ticket_product_id, is_additional, is_quote_recommendation}]` — reconciliado
 * contra `5__Requerimientos_Servicios.xlsx` (hoja "Modelo de Datos") y el mockup real
 * (`saveInforme()` arma exactamente ese shape); no es la tabla EAV `INFORME_HALLAZGO` que
 * proponía el Excel ni el JSONB de forma fija que sugería la sección 4 antes de este batch, ver
 * memory/project_sesion_20260810_batch8_informe_inspeccion.md.
 *
 * `ticket_id` es UNIQUE — un ticket tiene como máximo un informe (Modelo de Datos, fila
 * INFORME_INSPECCION.ticket_id). `firma_cliente_imagen` la escribe el Informe móvil (Batch 10,
 * REQ-254 RN2) sobre este mismo registro — nunca un informe paralelo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('inspection_reports'), function (Blueprint $table): void {
            $table->id();
            $table->string('numero')->unique('servicios_inspection_reports_numero_uq'); // INF-AÑO-NNNN

            $table->foreignId('ticket_id')
                ->unique('servicios_inspection_reports_ticket_uq')
                ->constrained($this->table('tickets'), 'id', 'servicios_inspection_reports_ticket_fk')
                ->cascadeOnDelete();

            $table->string('modo', 20)->default('formulario'); // formulario | archivo_subido (REQ-243)

            $table->date('fecha_inspeccion')->nullable();
            $table->string('hora_inicio', 10)->nullable();
            $table->string('hora_fin', 10)->nullable();

            // FK "suelto" a users.id, mismo criterio ya usado por tickets.internal_technician_id
            // (ver docblock de 2026_08_02_100001_create_servicios_tickets_table.php) — el técnico
            // responsable del informe es el mismo usuario que Ticket::internal_technician_id.
            $table->unsignedBigInteger('internal_technician_id')->nullable();

            $table->jsonb('findings')->nullable(); // ver docblock de la clase

            $table->text('conclusion')->nullable(); // obligatorio para completar (REQ-241 RN2), no a nivel de columna
            $table->boolean('requiere_seguimiento')->default(false);
            $table->text('proximos_pasos')->nullable(); // NULL si requiere_seguimiento=false (REQ-241 RN3)

            $table->string('firma_tecnico_nombre')->nullable();
            $table->string('firma_cliente_nombre')->nullable();
            $table->string('firma_cliente_imagen')->nullable(); // storage_key, solo desde Informe móvil

            $table->string('archivo_adjunto')->nullable(); // storage_key, solo si modo=archivo_subido

            $table->string('estado', 20)->default('pending'); // pending | completed — gate de REQ-219/REQ-218

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('internal_technician_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inspection_reports'));
    }
};
