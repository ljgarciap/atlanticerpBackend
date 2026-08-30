<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-193/195/196 (REQ-130/132/133) — líneas de producto de una orden de compra.
 *
 * `catalog_product_id` y `sales_project_id` son FKs CROSS-SCHEMA hacia `ventas_diseno`
 * (`catalog_products`, `sales_projects`) — este módulo reutiliza el Catálogo y los Pedidos ya
 * construidos en Ventas & Diseño en vez de duplicarlos (ver docs/specs/
 * compras-proveedores-sprint2.md). `$this->table()` no sirve para estas dos columnas porque
 * resuelve al schema DE ESTE módulo (`compras`); se arma el nombre calificado a mano.
 *
 * `catalog_product_id` nunca es null en la práctica: tanto agregar un producto existente
 * (REQ-130) como crear uno nuevo (REQ-131) terminan con un producto real en `catalog_products`
 * antes de crear la línea — pero se deja nullable con `nullOnDelete()` por el mismo criterio ya
 * usado en `quote_items.catalog_product_id` (si el producto se borra del catálogo, la línea ya
 * generada no debe desaparecer, solo pierde la trazabilidad).
 *
 * `sales_project_id` nullable = "Ninguno (stock)" (REQ-133 RN, Escenario 2) — asignación
 * independiente por línea, nunca heredada de la orden completa.
 *
 * Constraints nombrados explícitamente (nombre autogenerado con schema calificado supera 63
 * bytes de Postgres y trunca en silencio — ver memory/
 * feedback_tenant_migration_constraint_name_truncation.md).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';

        Schema::create($this->table('purchase_order_lines'), function (Blueprint $table) use ($ventasDisenoSchema): void {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained($this->table('purchase_orders'), 'id', 'purchase_order_lines_order_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'purchase_order_lines_product_fk')
                ->nullOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('additional_cost_percent', 5, 2)->nullable();
            $table->foreignId('sales_project_id')->nullable()
                ->constrained("{$ventasDisenoSchema}.sales_projects", 'id', 'purchase_order_lines_project_fk')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_order_lines'));
    }
};
