<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización-A (REQ-024 a 032): campos base del formulario. Todos los campos de
 * cliente/proyecto/entrega quedan nullable a nivel de BD a propósito — recién son
 * obligatorios al "generar" la cotización (REQ-047, Cotización-C, todavía sin
 * implementar), mismo patrón que pipeline_cards con sus gates de etapa.
 *
 * status arranca en 'draft' — Cotización-D (REQ-086, guardar borrador) ya tiene
 * sentido desde el día 1: toda cotización nace como borrador hasta que se genere.
 * folio (REQ-051, SCRUM-62) NO se agrega todavía — pertenece a la generación
 * atómica de Cotización-D, agregar la columna ahora sin esa lógica sería un campo
 * muerto.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('quotes'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('master_client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ruc')->nullable();
            $table->string('description')->nullable();
            // "Diseñador a cargo" (REQ-029) — siempre el usuario que crea, bloqueado en
            // el frontend; no hay reasignación por Gerencia como sí existe en Pipeline.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('architect_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('delivery_type', ['single', 'partial'])->nullable();
            $table->enum('status', ['draft', 'sent', 'approved'])->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('quotes'));
    }
};
