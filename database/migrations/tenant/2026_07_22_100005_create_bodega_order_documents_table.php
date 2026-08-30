<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-341) — documento generado de un pedido (Guía de entrega,
 * Estatus de pedido). Mismo shape que `project_documents` de CRM (storage_key + uploaded_by),
 * simplificado: sin versionado ni categorías múltiples, porque acá cada `tipo` es un documento
 * regenerable bajo demanda, no un archivo subido por un usuario — una regeneración crea una fila
 * nueva, nunca edita la existente (append-only, sin `updated_at`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $authSchema = Tenant::current()->slug.'_auth';

        Schema::create($this->table('order_documents'), function (Blueprint $table) use ($authSchema): void {
            $table->id();
            $table->foreignId('order_id')
                ->constrained($this->table('orders'), 'id', 'order_documents_order_fk')
                ->cascadeOnDelete();
            $table->string('tipo'); // guia_entrega|estatus_pedido
            $table->string('storage_key');
            $table->foreignId('uploaded_by')->nullable()
                ->constrained("{$authSchema}.users", 'id', 'order_documents_user_fk')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id', 'order_documents_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('order_documents'));
    }
};
