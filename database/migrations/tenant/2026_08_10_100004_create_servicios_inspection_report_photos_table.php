<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-241 RN1) — fotos "antes"/"después" de la intervención.
 * `categoria` es lo que distingue esta tabla de `ticket_attachments` (que no tiene categorías) —
 * mismo patrón de storage privado que `TicketAttachmentService` (disco `s3-private`, presigned URL
 * de 15 min, sin versionado).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('inspection_report_photos'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_report_id')
                ->constrained($this->table('inspection_reports'), 'id', 'servicios_insp_report_photos_report_fk')
                ->cascadeOnDelete();

            $table->string('categoria', 10); // antes | despues (REQ-241 RN1)
            $table->string('storage_key');

            $table->foreignId('uploaded_by')->nullable()
                ->constrained('users', 'id', 'servicios_insp_report_photos_uploader_fk')
                ->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('inspection_report_id');
            $table->index('categoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inspection_report_photos'));
    }
};
