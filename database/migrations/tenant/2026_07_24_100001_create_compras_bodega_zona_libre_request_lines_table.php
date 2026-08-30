<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de `bodega_zona_libre_requests` — mismo criterio que `purchase_order_lines`/`quote_items`:
 * `unit_cost_snapshot` congela el costo del producto al momento de armar la orden (no una
 * referencia en vivo a `catalog_products.cost`, que puede cambiar después). `catalog_product_id`
 * es FK CROSS-SCHEMA hacia `ventas_diseno.catalog_products`, mismo patrón que
 * `purchase_order_lines.catalog_product_id` (`$this->table()` resuelve al schema de ESTE módulo,
 * `compras` — se arma el nombre calificado a mano para la tabla de otro schema).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('bodega_zona_libre_request_lines'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('bodega_zona_libre_request_id')
                ->constrained($this->table('bodega_zona_libre_requests'), 'id', 'bodega_zl_request_lines_request_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'bodega_zl_request_lines_product_fk')
                ->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_cost_snapshot', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('bodega_zona_libre_request_lines'));
    }
};
