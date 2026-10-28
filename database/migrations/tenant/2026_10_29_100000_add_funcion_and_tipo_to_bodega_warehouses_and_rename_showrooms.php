<?php

declare(strict_types=1);

use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 8a (docs/specs/fase8-almacenes-configurables.md) — el código deja de buscar almacenes por nombre.
 *
 *  - `warehouses.funcion`: función de sistema del almacén (hoy solo `reserva_servicios`), única cuando tiene valor. Reemplaza el
 *    `where('name', 'Reserva Servicios')` de `InsumoService`. La bodega central ya se identifica por `despacho_por_defecto`.
 *  - `warehouses.tipo`: `bodega` | `exhibicion`. Reemplaza el `str_starts_with($name, 'Showroom')` de Admin&Cont.
 *  - Renombra los 3 almacenes de exhibición a nombres neutros. Los ids, el stock y las banderas no cambian.
 *
 * `down()` devuelve los nombres originales y quita las columnas.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    /** @var array<string, string> nombre anterior => nombre neutro */
    private const RENOMBRES = [
        'Showroom Obarrio' => 'Sala de exhibición 1',
        'Showroom SM' => 'Sala de exhibición 2',
        'Showroom Cliente' => 'Exhibición en cliente',
    ];

    public function up(): void
    {
        $warehouses = $this->table('warehouses');

        Schema::table($warehouses, function (Blueprint $table): void {
            $table->string('funcion', 40)->nullable()->after('name');
            $table->string('tipo', 20)->default('bodega')->after('funcion');
        });

        DB::statement("ALTER TABLE {$warehouses} ADD CONSTRAINT warehouses_tipo_check CHECK (tipo IN ('bodega', 'exhibicion'))");
        DB::statement("CREATE UNIQUE INDEX warehouses_funcion_unique ON {$warehouses} (funcion) WHERE funcion IS NOT NULL");

        DB::table($warehouses)->where('name', 'Reserva Servicios')->update(['funcion' => 'reserva_servicios']);
        DB::table($warehouses)->whereIn('name', array_keys(self::RENOMBRES))->update(['tipo' => 'exhibicion']);

        foreach (self::RENOMBRES as $viejo => $nuevo) {
            DB::table($warehouses)->where('name', $viejo)->update(['name' => $nuevo]);
        }
    }

    public function down(): void
    {
        $warehouses = $this->table('warehouses');

        foreach (self::RENOMBRES as $viejo => $nuevo) {
            DB::table($warehouses)->where('name', $nuevo)->update(['name' => $viejo]);
        }

        DB::statement('DROP INDEX IF EXISTS '.$this->schema().'.warehouses_funcion_unique');
        DB::statement("ALTER TABLE {$warehouses} DROP CONSTRAINT IF EXISTS warehouses_tipo_check");

        Schema::table($warehouses, function (Blueprint $table): void {
            $table->dropColumn(['funcion', 'tipo']);
        });
    }
};
