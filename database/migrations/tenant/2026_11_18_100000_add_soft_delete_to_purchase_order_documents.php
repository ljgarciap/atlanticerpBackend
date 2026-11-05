<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 24a — baja lógica de un documento del checklist de logística: el archivo se conserva en el almacenamiento y quedan
 * quién lo quitó, cuándo y por qué (el motivo es obligatorio en la API).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_order_documents'), function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('delete_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_order_documents'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deleted_at', 'delete_reason']);
        });
    }
};
