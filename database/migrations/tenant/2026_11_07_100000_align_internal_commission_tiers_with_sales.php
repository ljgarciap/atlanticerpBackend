<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 14g (docs/specs/fase14g-alinear-tramos-comision.md) — iguala los cortes de la tabla de comisiones internas de Admin & Contab (`commission_tiers`)
 * a los de Ventas (`sales_commission_tiers`): 0 / 20.000 / 35.000 / 60.000 / 80.000 / 120.000. Antes el segundo corte arrancaba en 20.001, y el del 4,5 %
 * en 81.001 (Ventas: 80.000), así que el mismo total daba otro porcentaje según la pantalla.
 *
 * Solo toca la tabla si SIGUE EXACTAMENTE en el valor sembrado (los 6 tramos con sus mínimos, máximos y porcentajes): una empresa que ya la
 * personalizó conserva sus valores (la pantalla le avisa si su escala difiere de la de Ventas). Los meses cerrados no se recalculan
 * (`commission_month_snapshots`). `down()` restaura los valores sembrados con la misma condición.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'admin_contab';

    /** Valores sembrados originalmente: [monto_minimo, monto_maximo, porcentaje]. */
    private const ANTES = [
        [0, 20000, 1.5], [20001, 35000, 2.0], [35001, 60000, 3.0], [60001, 81000, 4.0], [81001, 120000, 4.5], [120001, null, 5.0],
    ];

    /** Mismos valores que la escala de Ventas (`monto_maximo` en «.99», último tramo abierto). */
    private const DESPUES = [
        [0, 19999.99, 1.5], [20000, 34999.99, 2.0], [35000, 59999.99, 3.0], [60000, 79999.99, 4.0], [80000, 119999.99, 4.5], [120000, null, 5.0],
    ];

    public function up(): void
    {
        $this->reemplazar(self::ANTES, self::DESPUES);
    }

    public function down(): void
    {
        $this->reemplazar(self::DESPUES, self::ANTES);
    }

    /**
     * @param  list<array{0: int|float, 1: int|float|null, 2: float}>  $desde
     * @param  list<array{0: int|float, 1: int|float|null, 2: float}>  $hacia
     */
    private function reemplazar(array $desde, array $hacia): void
    {
        $tabla = $this->table('commission_tiers');
        $actuales = DB::table($tabla)->orderBy('orden')->get(['monto_minimo', 'monto_maximo', 'porcentaje']);

        if ($actuales->count() !== count($desde)) {
            return;
        }
        foreach ($actuales as $i => $fila) {
            [$min, $max, $pct] = $desde[$i];
            $mismoMax = $max === null ? $fila->monto_maximo === null : $fila->monto_maximo !== null && abs((float) $fila->monto_maximo - $max) < 0.005;
            if (abs((float) $fila->monto_minimo - $min) >= 0.005 || ! $mismoMax || abs((float) $fila->porcentaje - $pct) >= 0.005) {
                return; // personalizada: se respeta
            }
        }

        foreach ($hacia as $i => [$min, $max, $pct]) {
            DB::table($tabla)->where('orden', $i + 1)->update(['monto_minimo' => $min, 'monto_maximo' => $max, 'porcentaje' => $pct, 'updated_at' => now()]);
        }
    }
};
