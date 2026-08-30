<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-155 (Logística, SCRUM-218) — checklist de documentos del envío (factura comercial,
 * declaración de nacionalización, permiso de importación, etc.). Sin versionado (a diferencia de
 * `crm.project_documents`) — ningún criterio de aceptación pide reemplazar un documento ya
 * subido, solo subir y volver a consultar.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('purchase_order_documents'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained($this->table('purchase_orders'), 'id', 'purchase_order_documents_order_fk')
                ->cascadeOnDelete();
            $table->string('category');
            $table->string('original_filename');
            $table->string('storage_key');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_order_documents'));
    }
};
