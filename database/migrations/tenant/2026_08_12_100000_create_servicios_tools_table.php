<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (Grupo D, REQ-268→272) — inventario de herramientas
 * propias del equipo de Servicios. Catálogo de 4 estados confirmado por Jira (resuelve el punto
 * abierto #2 de docs/architecture/servicios-fase4-diseno.md, sección 7): buen estado | dañada |
 * desgaste | perdida — persistidos con claves ASCII en inglés (good|damaged|worn|lost), mismo
 * criterio que el resto de enums del módulo (`Ticket::ESTADOS`, etc.) — la etiqueta en español
 * vive en frontend/PDF, no en la columna.
 *
 * `assigned_to_technician_id` referencia `internal_technicians` (NO `users`, a diferencia de
 * `tickets.internal_technician_id`) — REQ-270 "Asignado a" es siempre un perfil de Técnico
 * Interno o "En bodega de herramientas" (NULL), nunca una cuenta de usuario cruda.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('tools'), function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->string('codigo_unico')->unique();
            $table->string('estado')->default('good');

            $table->foreignId('assigned_to_technician_id')->nullable()
                ->constrained($this->table('internal_technicians'), 'id', 'servicios_tools_technician_fk')
                ->nullOnDelete();
            $table->date('assigned_since')->nullable();

            // REQ-269 RN1/RN4 — snapshot del nombre de quien tenía la herramienta al momento de
            // Dañada/Perdida, o "Sin asignar al momento del incidente". NULL fuera de esos 2
            // estados (RN2/RN4) — nunca FK, se limpia solo al volver a Buen estado.
            $table->string('responsable_incidente')->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index('nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tools'));
    }
};
