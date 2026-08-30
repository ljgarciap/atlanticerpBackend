<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 4 (REQ-248) — fotos/videos/archivos de referencia adjuntados al crear
 * un ticket. Sin versionado/categoria/zone (a diferencia de `crm.project_documents`): siempre
 * privado, sin reemplazo de versión — cada adjunto es una fila independiente, se borran juntos con
 * el ticket (RN2: límite de cantidad/tamaño se valida en la app vía ServiciosSettingsService, no
 * acá).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('ticket_attachments'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')
                ->constrained($this->table('tickets'), 'id', 'servicios_ticket_attachments_ticket_fk')
                ->cascadeOnDelete();

            $table->string('nombre_archivo');
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users', 'id', 'servicios_ticket_attachments_uploader_fk')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_attachments'));
    }
};
