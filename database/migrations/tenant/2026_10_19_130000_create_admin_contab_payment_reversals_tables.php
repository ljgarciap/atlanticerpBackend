<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1168 F4 — anulación de cobros y devolución de dinero al cliente: se SOLICITAN (Felix o Yaneth) y los APRUEBA Mark; al aprobar se ejecutan. Nada se
 * borra: la anulación marca el cobro y deja contra-movimientos en el libro y en la caja.
 *
 * `payment_reversal_requests`:
 *  - tipo `anulacion`: `payment_id` es el cobro a anular.
 *  - tipo `devolucion`: `source_type`/`source_id` (`cobro` | `abono` | `nota_credito`) es el saldo a favor del que sale el dinero, `monto` lo que se
 *    devuelve, y `bank_account_id`/`referencia`/`comprobante_key` el respaldo de la salida (cuenta o caja de salida, referencia y comprobante adjunto).
 *  - estado: solicitada → ejecutada | rechazada.
 *
 * `commission_adjustments`: cuando reversar un cobro deja sin cobrar un pedido cuya comisión interna ya se había liquidado, la comisión pagada no se toca
 * (ni se recalcula el mes cerrado): se registra un DESCUENTO que se resta en la siguiente liquidación del vendedor (decisión de Luis, 2026-10-19).
 *
 * `payments`: marca de anulación (cuándo, quién y por qué); el cobro anulado queda en el historial con ese estado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->timestamp('anulado_at')->nullable()->after('cobros_v2');
            $table->unsignedBigInteger('anulado_by')->nullable()->after('anulado_at');
            $table->text('motivo_anulacion')->nullable()->after('anulado_by');
        });

        Schema::create($this->table('payment_reversal_requests'), function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 20);
            $table->unsignedBigInteger('master_client_id')->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->string('source_type', 20)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('monto', 12, 2)->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('referencia')->nullable();
            $table->string('comprobante_key')->nullable();
            $table->text('motivo');
            $table->string('estado', 20)->default('solicitada');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create($this->table('commission_adjustments'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vendedor_id')->index();
            $table->unsignedBigInteger('pedido_id')->nullable();   // tarjeta de Pipeline del pedido (el `pedido_id` de las filas de comisión)
            $table->decimal('monto', 12, 2);                       // lo que se descuenta (positivo)
            $table->decimal('monto_aplicado', 12, 2)->default(0);  // cuánto ya se descontó en liquidaciones
            $table->text('motivo');
            $table->unsignedBigInteger('source_movement_id')->nullable();
            $table->unsignedBigInteger('applied_snapshot_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('commission_adjustments'));
        Schema::dropIfExists($this->table('payment_reversal_requests'));
        Schema::table($this->table('payments'), function (Blueprint $table): void {
            $table->dropColumn(['anulado_at', 'anulado_by', 'motivo_anulacion']);
        });
    }
};
