<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 20 (SCRUM-612→617, REQ-538) — folio `NNNN-AAAA` de reportes de Caja Chica, particionado
 * por año (a diferencia de `credit_note_folio_counters`/`invoice_folio_counters`, que son un
 * contador global sin año): el propio mockup del ticket muestra el año embebido en el número
 * (`0001-2026`), reiniciando en 0001 cada año — mismo criterio de partición que
 * `servicios.ticket_folio_counters` (PK compuesta), simplificado a solo año porque acá no hay
 * prefijo por tipo. Ver `AdminContPettyCashReportFolioService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('petty_cash_report_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('anio')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('petty_cash_report_folio_counters'));
    }
};
