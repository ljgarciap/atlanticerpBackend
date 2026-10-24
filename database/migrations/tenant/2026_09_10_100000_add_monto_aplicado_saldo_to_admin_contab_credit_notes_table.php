<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-877 parte 1 — el Estado de Cuenta necesita, por cada nota de crédito, el monto que
 * REALMENTE redujo el saldo de la factura de origen (distinto del `monto` total de la nota cuando
 * parte se devolvió en efectivo al cliente — decisión D-6 de Luis). `CreditNoteService::
 * applyToBalance()` ya calcula ese valor (`min(nota.monto, saldoPendiente)`), pero no lo
 * persistía; se guarda acá para no tener que recalcularlo de forma frágil contra el estado actual
 * de la factura (que ya cambió). Nullable: notas viejas se rellenan con el comando
 * `admin-contab:backfill-credit-note-applied-amount`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->decimal('monto_aplicado_saldo', 12, 2)->nullable()->after('itbms');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('credit_notes'), function (Blueprint $table): void {
            $table->dropColumn('monto_aplicado_saldo');
        });
    }
};
