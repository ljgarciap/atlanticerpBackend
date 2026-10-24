<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-1145 — costos adicionales opcionales de la orden de compra (importación, flete, manejo,
 * otros). Nunca se suman a `total_amount` (que sigue siendo "solo productos" y alimenta PDF,
 * reportes y reclamos): el total a pagar al proveedor se deriva en `PurchaseOrder::payableTotal()`.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->decimal('import_cost', 12, 2)->nullable();
            $table->decimal('freight_cost', 12, 2)->nullable();
            $table->decimal('handling_cost', 12, 2)->nullable();
            $table->decimal('other_cost', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_orders'), function (Blueprint $table): void {
            $table->dropColumn(['import_cost', 'freight_cost', 'handling_cost', 'other_cost']);
        });
    }
};
