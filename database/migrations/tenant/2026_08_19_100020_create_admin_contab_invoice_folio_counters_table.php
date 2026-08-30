<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441). Folio F-XXXX (REQ-441 RN4) — a diferencia
 * de `QuoteFolioCounter`/`TicketFolioCounter` (consecutivo por año), el formato de factura no
 * lleva el año embebido en el número visible, así que un contador por año colisionaría entre
 * años (F-0001 del año 1 vs F-0001 del año 2, ambos únicos en `invoices.numero`). Por eso es un
 * contador global de una sola fila (`id` fijo en 1), mismo mecanismo atómico
 * (`INSERT ... ON CONFLICT DO NOTHING` + `lockForUpdate()`) que `QuoteFolioService::next()`,
 * solo que sin partir por año. Ver `AdminContInvoiceFolioService::next()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('invoice_folio_counters'), function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('invoice_folio_counters'));
    }
};
