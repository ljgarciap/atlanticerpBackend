<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-265 (REQ-202) — captura el estado de stock del producto AL MOMENTO de crear la línea de
 * la orden (disponible|bajo_stock|sin_stock, ver InventoryKpiService::stockState()). No se puede
 * derivar retroactivamente — el estado de stock cambia con el tiempo (confirmado en el Excel de
 * requerimientos, REQ-202 nota técnica). Nullable: las líneas creadas ANTES de este batch no
 * tienen este dato — el panel de Reportes las excluye del cálculo en vez de asumir un valor.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    public function up(): void
    {
        Schema::table($this->table('purchase_order_lines'), function (Blueprint $table): void {
            $table->string('stock_state_at_order')->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('purchase_order_lines'), function (Blueprint $table): void {
            $table->dropColumn('stock_state_at_order');
        });
    }
};
