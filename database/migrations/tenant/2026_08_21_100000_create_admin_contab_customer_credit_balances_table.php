<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 3 de Facturación (SCRUM-519→523, REQ-442) — fuente única de verdad de "saldo a favor",
 * compartida a futuro por Facturación/Cobros/Estado de Cuenta (`master_client_id` FK "suelta",
 * mismo criterio que `admin_contab.invoices.master_client_id` — schema de tenant distinto).
 *
 * Infraestructura fundacional a propósito: hoy NO existe ningún escritor real que cargue saldo —
 * Notas de Crédito (el único origen legítimo, según el ticket) es un batch muy posterior en el
 * plan (`docs/architecture/admin-contab-batches.md`). Todo cliente arranca en $0 hasta entonces;
 * `CreditBalanceService::forMasterClient()` devuelve 0.0 sin fila, así que Facturación funciona
 * hoy exactamente como si la feature no existiera (Escenario 3 de REQ-442) hasta que Notas de
 * Crédito empiece a escribir acá.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('customer_credit_balances'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('master_client_id')->unique();
            $table->decimal('saldo', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('customer_credit_balances'));
    }
};
