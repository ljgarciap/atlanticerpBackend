<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6 de Cobros (SCRUM-545, REQ-468) — comprobante de pago opcional adjuntado al registrar un
 * cobro. Mismo patrón que `servicios.ticket_attachments` (siempre privado, sin versionado): a lo
 * sumo 1 fila por `payment_id` (RN2/RN3 — opcional, único, permanente), por eso `unique` en vez de
 * índice simple, a diferencia de tickets que aceptan varios adjuntos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payment_attachments'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained($this->table('payments'))->cascadeOnDelete();
            $table->string('nombre_archivo');
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('payment_attachments'));
    }
};
