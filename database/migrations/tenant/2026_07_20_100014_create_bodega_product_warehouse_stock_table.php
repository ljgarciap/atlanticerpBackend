<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-244 (REQ-181) — desglose de stock por bodega. `catalog_product_id` es FK CROSS-SCHEMA
 * hacia `ventas_diseno.catalog_products` (mismo patrón que `catalog_products.provider_id` hacia
 * `compras.providers`, ver 2026_07_20_100003). Sin fila para un producto en una bodega = 0
 * unidades ahí, no se siembra una fila por cada producto × cada bodega.
 *
 * `catalog_products.stock_quantity` (el total plano ya existente) NO se toca ni se deriva de acá
 * en esta migración — quedaría desincronizado si se calculara la suma en dos lugares distintos;
 * el Backend Dev que implemente el endpoint decide si sigue siendo la fuente de verdad del total
 * o si pasa a ser un campo denormalizado recalculado. Fuera de alcance de esta migración.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('product_warehouse_stock'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'product_warehouse_stock_product_fk')
                ->cascadeOnDelete();
            $table->foreignId('warehouse_id')
                ->constrained($this->table('warehouses'), 'id', 'product_warehouse_stock_warehouse_fk')
                ->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['catalog_product_id', 'warehouse_id'], 'product_warehouse_stock_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('product_warehouse_stock'));
    }
};
