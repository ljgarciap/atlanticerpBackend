<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-230→232) — ítems de una Cotización de Servicio, 3 tipos
 * (`tipo`): product | labor | subcontracted (ver `ServiceQuoteItem`).
 *
 * `catalog_product_id` (solo tipo=product) es FK cross-schema hacia `ventas_diseno.catalog_products`
 * — mismo patrón ya usado por `ticket_products` (ver docblock de esa migración). Nullable también
 * para tipo=product cuando es "Otro (referencia libre)" (REQ-230 RN3, sin producto real de
 * catálogo, sin validación de margen).
 *
 * `external_technician_id` (solo tipo=subcontracted) es FK propia del mismo schema hacia
 * `external_technicians` (Batch 5).
 *
 * `cost_reference` es el costo/tarifa interno usado para calcular margen — copiado (snapshot) al
 * agregar la línea, NUNCA expuesto al cliente en el documento (Batch 12). `margin_percent` solo
 * tiene sentido para tipo=subcontracted (editable por línea, RN de REQ-232); para tipo=product el
 * margen se deriva de `unit_price`/`cost_reference` en vez de guardarse aparte (el bloqueo es
 * contra el mínimo global, no contra un valor propio por línea).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'servicios';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('service_quote_items'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('service_quote_id')
                ->constrained($this->table('service_quotes'), 'id', 'servicios_quote_items_quote_fk')
                ->cascadeOnDelete();

            $table->string('tipo'); // product | labor | subcontracted

            $table->foreignId('catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'servicios_quote_items_product_fk')
                ->nullOnDelete();
            $table->boolean('is_custom')->default(false);

            $table->foreignId('external_technician_id')->nullable()
                ->constrained($this->table('external_technicians'), 'id', 'servicios_quote_items_technician_fk')
                ->nullOnDelete();

            $table->text('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_reference', 12, 2)->nullable();
            $table->decimal('margin_percent', 5, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);

            $table->timestamps();

            $table->index('service_quote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('service_quote_items'));
    }
};
