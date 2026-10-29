<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 13 (2.1, decisión de Luis 2026-10-30) — el cambio de monto aprobado de una orden de compra deja una LÍNEA DE AJUSTE por la diferencia,
 * para que `total_amount` sea siempre la suma de las líneas (antes el total cambiaba y el detalle seguía sumando el valor anterior).
 *
 * `is_adjustment` marca la línea (sin producto, cantidad 1, `unit_cost` = `subtotal` = diferencia, que puede ser negativa) y `adjustment_note` dice
 * de qué es. Además concilia las órdenes que YA no suman: agrega la línea de ajuste con la diferencia. Aditiva y reversible: `down()` borra solo
 * las líneas que creó esta migración y las columnas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'compras';

    private const NOTA_CONCILIACION = 'Ajuste de conciliación (cambio de monto anterior)';

    public function up(): void
    {
        Schema::table($this->table('purchase_order_lines'), function (Blueprint $table): void {
            $table->boolean('is_adjustment')->default(false);
            $table->string('adjustment_note', 160)->nullable();
        });

        $orders = $this->table('purchase_orders');
        $lines = $this->table('purchase_order_lines');
        $now = now();

        $descuadradas = DB::table("{$orders} as o")
            ->leftJoin("{$lines} as l", 'l.purchase_order_id', '=', 'o.id')
            ->groupBy('o.id', 'o.total_amount')
            ->havingRaw('ABS(o.total_amount - COALESCE(SUM(l.subtotal), 0)) > 0.004')
            ->select('o.id', 'o.total_amount', DB::raw('COALESCE(SUM(l.subtotal), 0) as suma'))
            ->get();

        foreach ($descuadradas as $o) {
            $diferencia = round((float) $o->total_amount - (float) $o->suma, 2);
            DB::table($lines)->insert([
                'purchase_order_id' => $o->id, 'catalog_product_id' => null, 'quantity' => 1,
                'unit_cost' => $diferencia, 'subtotal' => $diferencia,
                'is_adjustment' => true, 'adjustment_note' => self::NOTA_CONCILIACION,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table($this->table('purchase_order_lines'))->where('is_adjustment', true)->where('adjustment_note', self::NOTA_CONCILIACION)->delete();

        Schema::table($this->table('purchase_order_lines'), function (Blueprint $table): void {
            $table->dropColumn(['is_adjustment', 'adjustment_note']);
        });
    }
};
