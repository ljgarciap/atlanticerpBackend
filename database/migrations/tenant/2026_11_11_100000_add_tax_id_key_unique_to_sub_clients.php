<?php

declare(strict_types=1);

use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 17a — una identificación fiscal por tenant. Agrega `sub_clients.tax_id_key` (identificación sin espacios/guiones/puntos, en mayúsculas,
 * + DV), la calcula para las filas existentes y crea un índice único parcial (sin clave no entra: consumidor final).
 *
 * Si ya hay identificaciones repetidas, ABORTA y las lista: nunca fusiona ni borra clientes (tocarían proyectos, cotizaciones y facturas).
 * Hay que resolverlas a mano antes de aplicarla. `down()` quita el índice y la columna.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    public function up(): void
    {
        $tabla = $this->table('sub_clients');

        Schema::table($tabla, function (Blueprint $table): void {
            $table->string('tax_id_key', 150)->nullable()->after('tax_id_dv');
        });

        /** @var array<string, list<array{id: int, business_name: string}>> $porClave */
        $porClave = [];
        foreach (DB::table($tabla)->select('id', 'business_name', 'tax_id', 'tax_id_dv')->orderBy('id')->get() as $fila) {
            $clave = SubClient::taxIdKey($fila->tax_id, $fila->tax_id_dv);
            if ($clave === null) {
                continue;
            }
            $porClave[$clave][] = ['id' => (int) $fila->id, 'business_name' => (string) $fila->business_name];
            DB::table($tabla)->where('id', $fila->id)->update(['tax_id_key' => $clave]);
        }

        $repetidos = array_filter($porClave, static fn (array $filas): bool => count($filas) > 1);
        if ($repetidos !== []) {
            $lista = [];
            foreach ($repetidos as $clave => $filas) {
                $lista[] = $clave.' → '.implode(', ', array_map(static fn (array $f): string => "#{$f['id']} {$f['business_name']}", $filas));
            }
            // Deshace la columna para que la migración se pueda repetir tras resolver los repetidos.
            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('tax_id_key');
            });

            throw new RuntimeException(
                'Hay clientes con la misma identificación fiscal en '.$tabla.'; fusiónelos o corríjalos a mano y vuelva a migrar:'.PHP_EOL
                .implode(PHP_EOL, $lista)
            );
        }

        DB::statement('CREATE UNIQUE INDEX sub_clients_tax_id_key_unique ON '.$tabla.' (tax_id_key) WHERE tax_id_key IS NOT NULL');
    }

    public function down(): void
    {
        $tabla = $this->table('sub_clients');

        DB::statement('DROP INDEX IF EXISTS '.$this->schema().'.sub_clients_tax_id_key_unique');
        Schema::table($tabla, function (Blueprint $table): void {
            $table->dropColumn('tax_id_key');
        });
    }
};
