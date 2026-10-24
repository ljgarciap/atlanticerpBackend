<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-929 (B1a, ver docs/adr/ADR-SCRUM929-facturacion-parcial-o-completa.md) — vínculo explícito
 * `OrderItem` → `QuoteItem` de origen. Nunca existió: hasta hoy el único enlace era
 * `catalog_product_id`, nullable (ítem personalizado) y repetible entre partes de una misma
 * cotización, por lo que "qué ítem de la cotización es este" era ambiguo. Facturar por entrega
 * parcial necesita saber, por `quote_item_id`, cuánto de cada ítem ya se facturó.
 *
 * Referencia "suelta", sin constraint física: `quote_items` vive en otro schema del tenant
 * (`ventas_diseno`), mismo criterio que `admin_contab.invoices.order_id` → `bodega.orders`. Un
 * consumidor que necesite la garantía (B2) debe verificar que el QuoteItem siga existiendo y, si
 * no, degradar a facturación `completo`.
 *
 * Nullable a propósito: los pedidos existentes no lo tienen — ver
 * `bodega:backfill-order-item-quote-links` (best-effort, no garantizado para todos).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->unsignedBigInteger('quote_item_id')->nullable()->after('catalog_product_id');
            $table->index('quote_item_id', 'bodega_order_items_quote_item_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('order_items'), function (Blueprint $table): void {
            $table->dropIndex('bodega_order_items_quote_item_id_idx');
            $table->dropColumn('quote_item_id');
        });
    }
};
