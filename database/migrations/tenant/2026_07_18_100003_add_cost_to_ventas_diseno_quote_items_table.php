<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-033 (fórmulas de Tipo de Precio): Tarifa Premium se calcula como
 * costo ÷ 0.65, un dato distinto de `unit_price` (que sigue siendo el precio
 * de venta tecleado, base de la cadena Público→Proyecto→Especial). Opcional —
 * un ítem sin costo cargado simplemente no puede calcular Premium.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->decimal('cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quote_items'), function (Blueprint $table): void {
            $table->dropColumn('cost');
        });
    }
};
