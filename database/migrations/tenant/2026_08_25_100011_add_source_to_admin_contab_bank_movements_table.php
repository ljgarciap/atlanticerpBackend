<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601) — ver docs/adr/ADR-SCRUM597-601-batch18-arqueo-caja.md,
 * reconciliación punto 3. `bank_movements` (ledger fundacional, Batch 1) nunca tuvo trazabilidad
 * hacia el registro de origen — necesaria para que el Arqueo del día (REQ-524) pueda mostrar el
 * monto exacto de una "devolución" ya aplicada (`CreditNoteService::applyToBalance()` solo
 * registra el *excedente*, no el monto completo de la nota) sin tener que recalcular esa fórmula
 * a mano. `source_type`: payment | credit_note | architect_commission — nullable a propósito, no
 * todo movimiento futuro va a tener un origen trazable de este tipo cerrado.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('bank_movements'), function (Blueprint $table): void {
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('bank_movements'), function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
