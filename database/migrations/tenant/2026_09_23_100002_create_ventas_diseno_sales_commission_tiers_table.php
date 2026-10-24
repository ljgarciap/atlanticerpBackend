<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCRUM-944/945 — auditoría de valores de negocio hardcodeados. `SalesTotalsService::percentFor()`
 * tenía la tabla de comisión escalonada de vendedores hardcodeada como `match(true)`
 * ($20k/$35k/$60k/$80k/$120k → 1.5/2.0/3.0/4.0/4.5/5.0%). Ya se había relevado una vez
 * (`docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md` §1, 2026-08-25) y Luis decidió
 * entonces mantenerla hardcodeada a propósito ("deuda conocida"); revisitado y superado el
 * 2026-09-23 — Luis pidió migrarla. Mismo patrón exacto que `commission_tiers` de Admin&Cont
 * (`monto_minimo`/`monto_maximo` nullable/`porcentaje`/`orden`), tabla separada a propósito — son
 * dos conceptos de negocio distintos (comisión de vendedor por volumen de pipeline vs. comisión
 * interna de Admin&Cont por facturación/cobro real), no se fusionan.
 *
 * Seed = LOS MISMOS 6 tramos que ya estaban hardcodeados en `SalesTotalsService::percentFor()`
 * (comportamiento preservado exactamente, solo cambia el storage): los cortes originales eran
 * `$total < 20000/35000/60000/80000/120000` (estrictamente menor) — para replicar esa semántica
 * con el modelo de rango inclusivo [monto_minimo, monto_maximo] ya usado por `commission_tiers`,
 * cada `monto_maximo` queda en "corte - 0.01" (un centavo antes del siguiente tramo), nunca en el
 * corte redondo — así un total EXACTAMENTE en el corte (ej. 20000.00) cae en el tramo de arriba,
 * igual que con el `<` original.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        Schema::create($this->table('sales_commission_tiers'), function (Blueprint $table): void {
            $table->id();
            $table->decimal('monto_minimo', 12, 2);
            $table->decimal('monto_maximo', 12, 2)->nullable();
            $table->decimal('porcentaje', 5, 2);
            $table->unsignedSmallInteger('orden');
            $table->timestamps();
        });

        $now = now();
        DB::table($this->table('sales_commission_tiers'))->insert([
            ['monto_minimo' => 0,        'monto_maximo' => 19999.99,  'porcentaje' => 1.5, 'orden' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 20000,    'monto_maximo' => 34999.99,  'porcentaje' => 2.0, 'orden' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 35000,    'monto_maximo' => 59999.99,  'porcentaje' => 3.0, 'orden' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 60000,    'monto_maximo' => 79999.99,  'porcentaje' => 4.0, 'orden' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 80000,    'monto_maximo' => 119999.99, 'porcentaje' => 4.5, 'orden' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['monto_minimo' => 120000,   'monto_maximo' => null,      'porcentaje' => 5.0, 'orden' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sales_commission_tiers'));
    }
};
