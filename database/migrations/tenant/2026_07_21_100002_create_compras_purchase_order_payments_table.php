<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-253 (REQ-190) — historial de pagos de una orden. RN2 del ticket: "un pago registrado es
 * permanente, nunca editable ni borrable" — por eso no hay `updated_at` (ver
 * PurchaseOrderPayment::UPDATED_AT = null) ni soft delete, y el controller nunca expone un
 * endpoint de update/destroy para este recurso.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::create($this->table('purchase_order_payments'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained($this->table('purchase_orders'), 'id', 'purchase_order_payments_order_fk')
                ->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_proof_storage_key');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_order_payments'));
    }
};
