<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6 de Cobros (SCRUM-545→548, REQ-468→471). Folio REC-XXX (REQ-469 RN1) — mismo mecanismo
 * de contador global de una sola fila que `AdminContInvoiceFolioCounter` (F-XXXX). Ver
 * `AdminContPaymentFolioService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payment_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('payment_folio_counters'));
    }
};
