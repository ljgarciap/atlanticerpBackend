<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — registro de cobros. `master_client_id` es
 * FK "suelta" (cross-schema, mismo criterio que `admin_contab.invoices.master_client_id`);
 * `bank_account_id` es FK real porque `bank_accounts` vive en el mismo schema.
 *
 * `estado` (decisión de Luis 2026-08-23): todos los métodos arrancan `confirmado` salvo
 * `link_pago`, que arranca `esperando_confirmacion` — coincide con el único ejemplo real de
 * REQ-463 Escenario 2. El flujo real de conciliación (subir comprobante, marcar confirmado a
 * mano) es Batch 6 (REQ-468→471) — acá solo se crea la columna con ese valor inicial.
 *
 * `timestamps()` completo (a diferencia de `invoices`, que solo tiene `created_at`): un pago SÍ
 * se espera que cambie de estado después de creado (Batch 6), así que `updated_at` importa acá.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payments'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id');
            $table->decimal('monto_recibido', 12, 2);
            $table->decimal('saldo_favor_aplicado', 12, 2)->default(0);
            $table->string('metodo_pago', 30);
            $table->foreignId('bank_account_id')->nullable()
                ->constrained($this->table('bank_accounts'))->nullOnDelete();
            $table->string('numero_documento_retencion')->nullable();
            $table->text('comentario_ajuste')->nullable();
            $table->string('estado', 30)->default('confirmado');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('master_client_id');
        });

        Schema::create($this->table('payment_invoices'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained($this->table('payments'))->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained($this->table('invoices'))->cascadeOnDelete();
            $table->decimal('monto_aplicado', 12, 2);

            $table->unique(['payment_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('payment_invoices'));
        Schema::dropIfExists($this->table('payments'));
    }
};
