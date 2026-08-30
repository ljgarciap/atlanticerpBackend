<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 11 de Admin&Cont (SCRUM-559→564, REQ-482→487) — folio NC-XXXX de Notas Crédito, mismo
 * mecanismo atómico (`INSERT ... ON CONFLICT DO NOTHING` + `lockForUpdate()`) que
 * `AdminContInvoiceFolioService`/`AdminContPaymentFolioService`. Contador global de una sola fila
 * (sin partir por año), igual criterio que `invoice_folio_counters`: `credit_notes.numero` es
 * único sin el año embebido. Ver `AdminContCreditNoteFolioService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('credit_note_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('credit_note_folio_counters'));
    }
};
