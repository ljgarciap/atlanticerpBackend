<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-903 (Saldo a Favor de Clientes) — una Nota de Crédito con `resultado='saldo_favor'` puede
 * consumirse parcialmente a través de varios cobros futuros (misma mecánica que un Abono de nivel
 * Cliente). NO confundir con `monto_aplicado_saldo` (agregada en
 * `2026_09_10_100000_add_monto_aplicado_saldo...`): esa columna es cuánto del monto DE ESTA NOTA
 * se usó para reducir la factura de origen al aprobarse — un concepto distinto de cuánto del
 * SALDO A FAVOR QUE ESTA NOTA GENERÓ ya se consumió después. El monto disponible como saldo a
 * favor de una nota `saldo_favor` es siempre `(monto - monto_aplicado_saldo) - monto_usado`
 * (accessor `AdminContCreditNote::montoDisponibleSaldoFavor()`), nunca `monto - monto_usado` a
 * secas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->decimal('monto_usado', 12, 2)->default(0)->after('monto_aplicado_saldo');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->dropColumn('monto_usado');
        });
    }
};
