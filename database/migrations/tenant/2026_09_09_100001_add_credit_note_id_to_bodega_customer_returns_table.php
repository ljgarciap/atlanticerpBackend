<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-865 punto 2 — `CustomerReturnService::finalizeForCreditNote()` ya recibía el número de la
 * nota de crédito generada pero nunca lo persistía — el listado de Devoluciones de Bodega no
 * tenía forma de armar el enlace "Ver nota de crédito" tras Finalizar. Guarda el id numérico (no
 * el folio) porque `AdminContCreditNoteController::show()/pdf()` resuelven por id, no por folio.
 * Sin FK cross-schema (mismo criterio ya usado en el resto del proyecto, ej. `AdminContInvoice.
 * order_id` → `bodega.orders.id`).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('customer_returns'), function (Blueprint $table): void {
            $table->unsignedBigInteger('credit_note_id')->nullable()->after('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('customer_returns'), function (Blueprint $table): void {
            $table->dropColumn('credit_note_id');
        });
    }
};
