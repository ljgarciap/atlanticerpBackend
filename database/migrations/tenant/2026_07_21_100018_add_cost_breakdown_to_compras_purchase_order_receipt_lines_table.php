<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-227 (REQ-164) — fix Pre-QA 2026-07-20, rechazado por marly: el prorrateo de
 * importación/flete/manejo/otros (proveedor no local) se calculaba en
 * `GoodsReceiptController::saveReceipt()` pero solo se pisaba como snapshot global en
 * `catalog_products` (2026_07_21_100016) — nunca quedaba una columna por línea, así que el
 * reparto proporcional del criterio de aceptación (REQ-164 Escenario 1) no era observable ni
 * quedaba registrado por línea, ni en el wizard ni en el detalle del ingreso ya guardado.
 *
 * Estas 4 columnas persisten el monto YA prorrateado que le tocó a ESA línea en ESE ingreso — a
 * diferencia del snapshot de `catalog_products`, que se sobreescribe con cada ingreso nuevo, esto
 * queda fijo como historial de lo que se cobró en el momento. Nulas para proveedor local (RN de
 * REQ-164: usa `itbms_amount`, no reparto).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_order_receipt_lines'), function (Blueprint $table): void {
            $table->decimal('import_cost_share', 12, 2)->nullable()->after('itbms_amount');
            $table->decimal('freight_cost_share', 12, 2)->nullable()->after('import_cost_share');
            $table->decimal('handling_cost_share', 12, 2)->nullable()->after('freight_cost_share');
            $table->decimal('other_cost_share', 12, 2)->nullable()->after('handling_cost_share');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_order_receipt_lines'), function (Blueprint $table): void {
            $table->dropColumn(['import_cost_share', 'freight_cost_share', 'handling_cost_share', 'other_cost_share']);
        });
    }
};
