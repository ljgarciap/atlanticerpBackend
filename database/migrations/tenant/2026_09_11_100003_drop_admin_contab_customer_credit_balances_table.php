<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-902/903 — retira `customer_credit_balances` (Batch 3 de Facturación, SCRUM-519→523,
 * REQ-442), reemplazada por el ledger de `abonos` (ver migración de creación en el mismo batch) +
 * `credit_notes.monto_usado` (ver migración hermana). Decisión de Luis: el escalar por cliente no
 * puede representar niveles de asociación (Cliente/Proyecto/Factura) ni trazabilidad de consumo
 * parcial por registro, que SCRUM-902/903 exigen explícitamente. `CreditBalanceService` y
 * `AdminContCustomerCreditBalance` se eliminan en el mismo commit que esta migración — no debe
 * quedar ningún lector/escritor de esta tabla en el código.
 *
 * `down()` no puede recuperar datos (la tabla ya estaba vacía en la práctica — ver docblock de la
 * migración de creación: "hoy sin ningún escritor real" hasta Batch 11, y desde ese batch solo
 * Notas de Crédito escribía, ahora reemplazado por `credit_notes.monto_usado` directamente).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::dropIfExists($this->table('customer_credit_balances'));
    }

    public function down(): void
    {
        Schema::create($this->table('customer_credit_balances'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id')->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->timestamps();
        });
    }
};
