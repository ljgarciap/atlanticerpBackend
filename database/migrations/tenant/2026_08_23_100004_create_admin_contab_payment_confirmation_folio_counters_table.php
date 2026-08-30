<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7 de Cobros (SCRUM-549→552, REQ-472→475). Folio CONF-XXX (REQ-475 RN2) — mismo mecanismo
 * de contador global de una sola fila que `AdminContPaymentFolioCounter` (REC-XXX), pero en su
 * propia tabla: RN2 exige que la numeración de confirmaciones sea "independiente del número de
 * recibo (REC-XXX) del cobro", así que comparte contador con `payment_folio_counters` rompería esa
 * regla. Ver `AdminContPaymentConfirmationFolioService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('payment_confirmation_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('payment_confirmation_folio_counters'));
    }
};
