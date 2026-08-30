<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización-C (REQ-043/044/045/046): observaciones (con notas automáticas de
 * entrega/instalación, ver Quote::observationsForDocument()), checkbox de
 * instalación (sin efecto en Totales, REQ-044), descuento adicional de Totales
 * (% o $, sobre el subtotal ya con descuentos de línea/global aplicados), y el
 * texto fijo de Condiciones (editable solo por quien tenga el permiso angosto
 * ventas_diseno.edit.conditions — mismo patrón que edit.approved/select_partner_price).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->text('observations')->nullable()->after('global_discount_percent');
            $table->boolean('includes_installation')->default(false)->after('observations');
            $table->enum('discount_totals_type', ['percent', 'amount'])->default('percent')->after('includes_installation');
            $table->decimal('discount_totals_value', 12, 2)->default(0)->after('discount_totals_type');
            $table->text('conditions_text')->nullable()->after('discount_totals_value');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('quotes'), function (Blueprint $table): void {
            $table->dropColumn(['observations', 'includes_installation', 'discount_totals_type', 'discount_totals_value', 'conditions_text']);
        });
    }
};
