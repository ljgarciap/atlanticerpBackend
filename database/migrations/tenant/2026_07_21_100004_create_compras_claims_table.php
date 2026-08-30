<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-257→261 (REQ-194→198) — Garantías y Reclamos. `code` NO se persiste acá — se deriva de
 * `id` en el modelo (`PurchaseOrderClaim::code()`, "R-" + id), mismo criterio que evitar una
 * tabla de contador separada cuando el id autoincremental de Postgres ya es atómico.
 *
 * `status_changed_at` — mismo patrón que `purchase_orders.status_changed_at` (REQ-139): base
 * para la bandera automática "⚠️ Atención" (REQ-194, umbral en `compras_settings.
 * claim_attention_days`, default 7 — valor literal del escenario de aceptación del ticket, no
 * inventado).
 *
 * `service_ticket_id` — REQ-198, alcance reducido: columna sembrada para no requerir otra
 * migración cuando exista Fase 4 (Servicios), pero el frontend SIEMPRE muestra "sin tickets
 * asociados" en este batch — ver PurchaseOrderClaimController::class.
 *
 * Sin tabla de historial de cambios de estado — con solo 2 estados posibles
 * (en_revision|resuelto), `status_changed_at` + `resolved_at` ya cubren el único escenario de
 * aceptación de REQ-196 ("historial de estados"); una tabla de auditoría completa sería
 * sobre-ingeniería para un campo binario.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('claims'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained($this->table('purchase_orders'), 'id', 'claims_order_fk')
                ->restrictOnDelete();
            $table->text('description')->nullable();
            $table->text('expected_resolution')->nullable();
            $table->string('status')->default('en_revision');
            $table->timestampTz('status_changed_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('service_ticket_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('claims'));
    }
};
