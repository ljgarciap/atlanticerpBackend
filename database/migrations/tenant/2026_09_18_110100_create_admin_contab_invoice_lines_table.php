<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-929 (B1b) — snapshot de LO QUE SE FACTURÓ, línea por línea. Fuente única para el PDF, para
 * el mapper de facturación electrónica (que necesita ITBMS por línea) y para responder "cuánto de
 * cada ítem de la cotización ya se facturó" (`quote_item_id`), que es lo que impide cobrar dos veces
 * la misma mercadería al combinar facturas parciales y "pedido completo".
 *
 * `quote_item_id` y `order_item_id` son referencias sueltas, sin constraint física: viven en otros
 * schemas del tenant (`ventas_diseno`, `bodega`) — mismo criterio que `invoices.order_id`. `invoice_id`
 * sí es del mismo schema pero sigue la convención del módulo (referencia suelta + índice): una
 * factura nunca se borra, se anula (`anulada_at`), así que no hay huérfanas por borrado.
 *
 * Importes desglosados (`subtotal` base + `itbms` + `total`) porque MSL/Digifact exigen la tasa por
 * línea y `order_items.itbms_rate_id` todavía no tiene ningún escritor (prep de facturación
 * electrónica): `itbms_rate_id` acá queda nullable hasta que B2 decida de dónde sale.
 * `cantidad` con 3 decimales por si una cotización trae cantidades fraccionarias (el pedido de
 * Bodega las redondea a entero, la cotización no).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    public function up(): void
    {
        Schema::create($this->table('invoice_lines'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('quote_item_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 12, 3);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->unsignedBigInteger('itbms_rate_id')->nullable();
            $table->decimal('itbms', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->index('invoice_id', 'invoice_lines_invoice_id_idx');
            $table->index('quote_item_id', 'invoice_lines_quote_item_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('invoice_lines'));
    }
};
