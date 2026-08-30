<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229). Folio COT-SERV-AÑO-NNNN, consecutivo por año calendario
 * — mismo patrón que `InspectionReportFolioCounter`/`ClaimSheetFolioCounter`-equivalente (PK
 * simple por año, no compuesta como `TicketFolioCounter`, porque acá no hay prefijo variable).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('service_quote_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('service_quote_folio_counters'));
    }
};
