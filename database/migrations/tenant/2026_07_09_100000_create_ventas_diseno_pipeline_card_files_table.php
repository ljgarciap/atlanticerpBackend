<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        // Sin versionado (a diferencia de crm.project_documents) — el modelo de datos
        // del cliente para TARJETA_ARCHIVO no lo pide, solo "agregar archivos" por tipo,
        // con retención permanente (REQ-052: nunca se borran).
        Schema::create($this->table('pipeline_card_files'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_card_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['design', 'proposal', 'signed_quote', 'approval_proof']);
            $table->string('file_name');
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('pipeline_card_files'));
    }
};
