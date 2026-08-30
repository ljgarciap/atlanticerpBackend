<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-250→252 (REQ-187→189) — seguimiento de pago. `payment_requested_at` marca el paso
 * "Enviar a pago" (notifica a Mark, no bloquea — RN de REQ-189). `pending_amount_change`/
 * `amount_change_requested_by` — RN7: cambiar el monto a pagar ANTES del primer pago registrado
 * requiere aprobación de Mark; se aplica a `total_amount` recién cuando Mark aprueba (ver
 * PurchaseOrderPaymentController::approveAmountChange()).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->timestampTz('payment_requested_at')->nullable()->after('status_changed_at');
            $table->decimal('pending_amount_change', 12, 2)->nullable()->after('total_amount');
            $table->foreignId('amount_change_requested_by')->nullable()
                ->constrained('users', 'id', 'purchase_orders_amount_change_requested_by_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('amount_change_requested_by');
            $table->dropColumn(['payment_requested_at', 'pending_amount_change']);
        });
    }
};
