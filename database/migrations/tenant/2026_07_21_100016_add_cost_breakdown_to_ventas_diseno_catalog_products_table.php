<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-237 (REQ-174) — desglose de costo editable en el detalle del producto: Costo de
 * importación, Flete, Manejo, Otros. Costo Total = cost + estas 4 columnas.
 *
 * REQ-164 (Ingreso de Mercancía) ya define de dónde salen estos montos para proveedor NO local:
 * se capturan como 4 totales por envío y se reprorratean por línea — el resultado de ese
 * prorrateo es justo lo que se persiste acá, reemplazando cualquier estimado manual anterior
 * (RN de REQ-166: "reemplazando cualquier costo adicional estimado que se hubiera capturado
 * antes"). Para proveedor LOCAL, REQ-164 dice explícitamente que estos 4 campos no aplican (solo
 * ITBMS 7%, que se sigue plegando dentro de `cost` como ya hacía GoodsReceiptController) — quedan
 * en 0 para productos de origen local.
 *
 * Hasta que exista un Ingreso de Mercancía real para un producto, estos campos son editables a
 * mano desde el modal de Inventario (SCRUM-237) como estimado de Compras.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->decimal('import_cost', 12, 2)->default(0)->after('cost');
            $table->decimal('freight_cost', 12, 2)->default(0)->after('import_cost');
            $table->decimal('handling_cost', 12, 2)->default(0)->after('freight_cost');
            $table->decimal('other_cost', 12, 2)->default(0)->after('handling_cost');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('catalog_products'), function (Blueprint $table): void {
            $table->dropColumn(['import_cost', 'freight_cost', 'handling_cost', 'other_cost']);
        });
    }
};
