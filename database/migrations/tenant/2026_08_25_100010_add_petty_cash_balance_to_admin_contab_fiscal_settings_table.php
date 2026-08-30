<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-520 RN1) — ver
 * docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md, reconciliación punto 1: Caja Chica todavía no
 * existe (Batch 20-21, posterior en el plan), así que "Saldo disponible hoy" no tiene de dónde leer
 * el saldo de caja menuda. Mismo criterio ya usado para `comision_externa_default_percent`
 * (migración `2026_08_25_100008`): se agrega al singleton de Configuración Fiscal en vez de
 * hardcodear el valor, editable por Mark vía `PUT /fiscal-settings`. Cuando Batch 20 construya el
 * ledger real de Caja Chica, este campo se reemplaza por el saldo calculado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->decimal('petty_cash_balance', 12, 2)->default(0);
        });

        DB::table($this->table('fiscal_settings'))->where('id', 1)->update(['petty_cash_balance' => 0]);
    }

    public function down(): void
    {
        Schema::table($this->table('fiscal_settings'), function (Blueprint $table): void {
            $table->dropColumn('petty_cash_balance');
        });
    }
};
