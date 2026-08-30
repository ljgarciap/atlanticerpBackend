<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238 RN1) — contador atómico del folio INF-AÑO-NNNN. Un único
 * prefijo ("INF"), así que a diferencia de `ticket_folio_counters` (PK compuesta prefijo+año) esta
 * tabla usa PK simple `year` — mismo patrón que `VentasDiseno\QuoteFolioCounter`/`QuoteFolioService`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        Schema::create($this->table('inspection_report_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inspection_report_folio_counters'));
    }
};
