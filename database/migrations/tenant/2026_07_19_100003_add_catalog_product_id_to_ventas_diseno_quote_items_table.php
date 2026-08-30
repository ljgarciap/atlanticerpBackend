<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-035/036/038: ahora que el Catálogo existe (ver migración de
 * catalog_products), un ítem puede venir de un producto real en vez de ser
 * 100% personalizado — exactamente el hueco que la migración original de
 * quote_items dejó documentado a propósito ("no hay columna
 * catalog_product_id/is_custom porque no existe nada contra qué
 * referenciar").
 *
 * `catalog_product_id` es solo trazabilidad — al seleccionar un producto
 * (QuoteItemController), `unit_price`/`cost`/`reference`/`description` se
 * COPIAN de una vez (no se recalculan en vivo desde acá), así que si el
 * producto de catálogo cambia de precio después no afecta cotizaciones ya
 * armadas. `nullOnDelete()`: si el producto se borra del catálogo, el ítem ya
 * generado conserva sus datos copiados, solo pierde la trazabilidad.
 *
 * `is_custom` (default true): todo ítem existente hoy es personalizado —
 * mantiene el comportamiento actual sin migración de datos.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->foreignId('catalog_product_id')->nullable()->after('quote_part_id')
                ->constrained($this->table('catalog_products'))->nullOnDelete();
            $table->boolean('is_custom')->default(true)->after('catalog_product_id');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->dropConstrainedForeignId('catalog_product_id');
            $table->dropColumn('is_custom');
        });
    }
};
