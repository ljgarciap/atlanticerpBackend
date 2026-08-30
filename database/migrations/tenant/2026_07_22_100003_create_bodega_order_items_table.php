<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-308 RN1, REQ-312, REQ-340) — ítem de un pedido, copiado
 * desde los `quote_items` de la cotización aprobada al crear el `Order` (una copia, no una
 * referencia en vivo — mismo criterio que `QuoteItem.unit_price`/`cost`).
 *
 * `catalog_product_id` es nullable porque `QuoteItem.is_custom=true` (ítem personalizado sin
 * producto de Catálogo) es un caso real y válido en Ventas & Diseño — `reference_snapshot`/
 * `description_snapshot` guardan lo pedido en ambos casos, así Status de Pedidos (REQ-340) no
 * depende de un join a `catalog_products` que además puede cambiar de descripción después de
 * vendido.
 *
 * `qty_requested`/`qty_reserved`/`qty_picked`/`qty_delivered` — el avance de picking/entrega
 * (REQ-340 RN1: "Solicitada" - "Entregado" = "Pendiente") lo escriben los batches de Pedidos
 * backend (A2), acá solo se define el shape de la columna.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('order_items'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('order_id')
                ->constrained($this->table('orders'), 'id', 'order_items_order_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'order_items_product_fk')
                ->nullOnDelete();

            $table->string('reference_snapshot')->nullable();
            $table->string('description_snapshot')->nullable();

            $table->unsignedInteger('qty_requested');
            $table->unsignedInteger('qty_reserved')->default(0);
            $table->unsignedInteger('qty_picked')->default(0);
            $table->unsignedInteger('qty_delivered')->default(0);

            $table->string('location_snapshot')->nullable();
            $table->boolean('found_at_location')->nullable();
            $table->string('shortage_reason')->nullable();

            $table->timestamps();

            $table->index('order_id', 'order_items_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('order_items'));
    }
};
