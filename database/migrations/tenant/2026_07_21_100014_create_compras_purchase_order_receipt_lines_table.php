<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-220→236 (REQ-157→173) — línea de un ingreso de mercancía. `confirmed_at` NULL = "Por
 * ingresar" (REQ-172/166); se setea al confirmar (REQ-173, "Ingresar a Inventario") o de
 * inmediato al guardar si la orden ya estaba "Recibido" (RN de REQ-166 — factura que llega
 * después de que la mercancía ya estaba físicamente en bodega). `warehouse_id` es la bodega
 * elegida al ingresar — REQ-173 RN: "no hay que volver a elegir bodega" al confirmar, ya vive acá.
 * `itbms_amount` es RN de REQ-164 (proveedor local, 7% por línea) — nulo para proveedor no local
 * (usa el prorrateo de los 4 totales del header, calculado on-the-fly, no persistido).
 *
 * `catalog_product_id`/`warehouse_id` son FKs cross-schema (ventas_diseno/bodega) — mismo patrón
 * y misma razón que `purchase_order_lines`/`product_warehouse_stock` (`$this->table()` resuelve
 * al schema de este módulo, no sirve para estas dos columnas).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        $ventasDisenoSchema = Tenant::current()->slug.'_ventas_diseno';
        $bodegaSchema       = Tenant::current()->slug.'_bodega';

        Schema::create($this->table('purchase_order_receipt_lines'), function (Blueprint $table) use ($ventasDisenoSchema, $bodegaSchema): void {
            $table->id();
            $table->foreignId('purchase_order_receipt_id')
                ->constrained($this->table('purchase_order_receipts'), 'id', 'receipt_lines_receipt_fk')
                ->cascadeOnDelete();
            $table->foreignId('catalog_product_id')
                ->constrained("{$ventasDisenoSchema}.catalog_products", 'id', 'receipt_lines_product_fk')
                ->cascadeOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('itbms_amount', 12, 2)->nullable();
            $table->foreignId('warehouse_id')
                ->constrained("{$bodegaSchema}.warehouses", 'id', 'receipt_lines_warehouse_fk');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('purchase_order_receipt_lines'));
    }
};
