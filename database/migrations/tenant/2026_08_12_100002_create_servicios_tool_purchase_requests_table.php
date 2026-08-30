<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-271/REQ-272) — solicitud de compra de herramientas,
 * puramente conceptual (a Felix/Administración, sin integración real). `source_tool_id` es NULL
 * para alta nueva (REQ-271 RN1: el formulario nunca captura código) y apunta a la unidad
 * existente que disparó el pedido de reposición (REQ-272 RN3: el `nombre` se deriva de esa unidad
 * server-side — ver `ToolService::createPurchaseRequest()` — nunca texto libre del cliente cuando
 * la solicitud viene de acá).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('tool_purchase_requests'), function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->unsignedInteger('cantidad');
            $table->string('estado')->default('solicitado'); // solicitado | recibida

            $table->foreignId('source_tool_id')->nullable()
                ->constrained($this->table('tools'), 'id', 'servicios_tool_purchase_req_source_fk')
                ->nullOnDelete();

            $table->foreignId('requested_by')->nullable()
                ->constrained('users', 'id', 'servicios_tool_purchase_req_user_fk')
                ->nullOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tool_purchase_requests'));
    }
};
