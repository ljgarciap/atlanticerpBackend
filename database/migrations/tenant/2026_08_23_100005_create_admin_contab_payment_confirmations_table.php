<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7 de Cobros (SCRUM-552, REQ-475) — registro de confirmación manual bancaria. RN2: "cada
 * confirmación genera su propio registro consecutivo (CONF-XXX), independiente del número de
 * recibo (REC-XXX)". `unique(payment_id)` — RN4 dice que la acción no puede deshacerse desde la
 * pantalla, así que a lo sumo 1 confirmación por cobro (un cobro que ya está `confirmado` nunca
 * vuelve a mostrar el botón, ver `PaymentService::confirm()`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payment_confirmations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->unique()->constrained($this->table('payments'))->cascadeOnDelete();
            $table->string('numero_confirmacion', 20)->unique();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('payment_confirmations'));
    }
};
