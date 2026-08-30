<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-269 RN3, REQ-271 RN4, REQ-272 RN4) — kardex propio de
 * Herramientas, independiente del kardex de Bodega (`Bodega\Models\InventoryMovement`) —
 * herramientas no son parte del inventario compartido de REQ-291 (eso aplica solo a insumos de
 * consumo, ver sección 2 del diseño). Solo consulta, inmutable — sin `updated_at`, mismo patrón
 * que `ExternalTechnicianRateHistory`. Reasignar (REQ-270) nunca escribe acá — solo Dañada/
 * Perdida/Desgaste (REQ-269) e Ingreso por recepción (REQ-271/272).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('tool_movements'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')
                ->constrained($this->table('tools'), 'id', 'servicios_tool_movements_tool_fk')
                ->cascadeOnDelete();

            $table->string('tipo'); // ingreso | damaged | lost | worn
            $table->text('detalle')->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users', 'id', 'servicios_tool_movements_user_fk')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('tool_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tool_movements'));
    }
};
