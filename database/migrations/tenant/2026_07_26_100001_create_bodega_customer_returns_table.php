<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
 * ADR-SCRUM473-489-devoluciones.md. Ciclo completo de devolución de cliente, siempre atado a una
 * Guía de Entrega real de un `Order` ya despachado (REQ-414) — no existe "devolución libre".
 *
 * `status` — pendiente|esperando_nota_credito|finalizado|rechazada. El sub-paso "sin firma" vs
 * "con firma, lista para confirmar recepción" (ambos "Pendiente" en la UI, REQ-413) se deriva de
 * `signed_document_path IS NULL`, no es un status aparte — mismo criterio que `Order.invoice_ready`
 * siendo un flag independiente del stage.
 *
 * `credit_note_notified_at` — sellado automático al confirmar recepción (REQ-410 RN1), simula el
 * aviso a Administración & Contabilidad; no hay canal real todavía, mismo criterio de stub que
 * `Order.invoice_ready`.
 *
 * Sin tabla de historial aparte (`ReturnStageHistory`): los pasos son fijos y cada uno tiene como
 * mucho una ocurrencia — los timestamps ya en esta fila (`created_at`,
 * `signed_document_uploaded_at`, `received_at`, `credit_note_notified_at`,
 * `finalized_at`/`rejected_at`) reconstruyen el historial completo (REQ-405) sin una tabla
 * append-only. Ver docblock del ADR para el razonamiento completo.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('customer_returns'), function (Blueprint $table) use ($authSchema): void {
            $table->id();
            $table->string('return_number')->unique('customer_returns_return_number_uq');

            $table->foreignId('order_id')
                ->constrained($this->table('orders'), 'id', 'customer_returns_order_fk');

            // pendiente|esperando_nota_credito|finalizado|rechazada
            $table->string('status')->default('pendiente');

            $table->string('contact_name');
            $table->string('contact_phone');

            $table->string('signed_document_path')->nullable();
            $table->timestamp('signed_document_uploaded_at')->nullable();

            $table->foreignId('received_by')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'customer_returns_received_by_fk')
                ->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('destination_warehouse_id')->nullable()
                ->constrained($this->table('warehouses'), 'id', 'customer_returns_destination_warehouse_fk')
                ->nullOnDelete();

            $table->timestamp('credit_note_notified_at')->nullable();
            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('rejected_by')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'customer_returns_rejected_by_fk')
                ->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('created_by')
                ->constrained("{$authSchema}.users", 'id', 'customer_returns_created_by_fk');

            $table->timestamps();

            $table->index('status', 'customer_returns_status_idx');
            $table->index('order_id', 'customer_returns_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('customer_returns'));
    }
};
