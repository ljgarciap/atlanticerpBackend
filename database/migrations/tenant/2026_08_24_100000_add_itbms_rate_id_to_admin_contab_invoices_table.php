<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10 (SCRUM-553→558, REQ-476→481) — cierra un gap encontrado al diseñar REQ-480 (mostrar el
 * "tratamiento ITBMS actual" de una factura para poder corregirlo): ninguna factura guardaba hasta
 * ahora qué tasa de `admin_contab.itbms_rates` se le aplicó — Batch 2-4 de Facturación solo persiste
 * `monto` total, sin desglose (ver docblock de `create_admin_contab_invoices_table`), y el desglose
 * fiscal de `InvoiceService::fiscalBreakdown()` siempre fue un cálculo de preview, nunca guardado.
 *
 * FK real (no "suelta") porque `itbms_rates` vive en el mismo schema `admin_contab` — a diferencia
 * de `order_id`/`master_client_id` en esta misma tabla, que sí cruzan schema de tenant.
 * `nullOnDelete()`: una tasa nunca se borra de verdad si es `es_base` (ver
 * `AdminContItbmsRateController::destroy()`), pero una tasa personalizada sí podría — una factura
 * vieja no debe romperse por eso, solo perder la trazabilidad de cuál era.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->foreignId('itbms_rate_id')->nullable()->after('quote_id')
                ->constrained($this->table('itbms_rates'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('invoices'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('itbms_rate_id');
        });
    }
};
