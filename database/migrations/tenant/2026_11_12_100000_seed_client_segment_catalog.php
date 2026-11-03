<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 18 (docs/specs/fase18-segmentos-de-cliente.md) — los segmentos de cliente pasan a ser un catálogo de la empresa (dominio `client_segment`).
 *
 *  - Quita la lista fija (`enum` → CHECK) de `sub_clients.category`: la validación vive en la aplicación, contra el catálogo.
 *  - Siembra los 4 segmentos históricos como entradas de SISTEMA con las MISMAS claves (los datos existentes no cambian de valor) y etiquetas
 *    neutras. Si la entrada ya existe (la empresa la editó) no se pisa. Cualquier otro valor ya guardado en `category` se siembra también,
 *    para que ningún dato quede huérfano.
 *
 * `down()` borra las entradas; la columna queda como `string` (solo se restaura el CHECK si todos los valores son los 4 originales).
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'ventas_diseno';

    /** @var array<string, array{string, string}> clave => [etiqueta, etiqueta en inglés] */
    private const SISTEMA = [
        'a_walkin'             => ['A · Visita directa', 'A · Walk-in'],
        'b_architect_designer' => ['B · Prescriptor', 'B · Prescriber'],
        'c_bidding'            => ['C · Licitación', 'C · Bidding'],
        'd_referred'           => ['D · Referido', 'D · Referred'],
    ];

    public function up(): void
    {
        $subClients = $this->table('sub_clients');
        DB::statement("ALTER TABLE {$subClients} DROP CONSTRAINT IF EXISTS sub_clients_category_check");
        Schema::table($subClients, function (Blueprint $table): void {
            $table->string('category', 60)->nullable()->change();
        });

        $entradas = Tenant::current()?->slug.'_auth.catalog_entries'; // el catálogo vive en el schema `auth`
        $now = now();
        $orden = 0;
        foreach (self::SISTEMA as $clave => [$etiqueta, $ingles]) {
            if (DB::table($entradas)->where('domain', 'client_segment')->where('key', $clave)->exists()) {
                $orden++;

                continue;
            }
            DB::table($entradas)->insert([
                'domain' => 'client_segment', 'key' => $clave, 'label' => $etiqueta, 'labels' => json_encode(['en' => $ingles], JSON_UNESCAPED_UNICODE),
                'sort_order' => $orden++, 'is_active' => true, 'is_system' => true, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $enUso = DB::table($subClients)->whereNotNull('category')->where('category', '!=', '')
            ->whereNotIn('category', array_keys(self::SISTEMA))->distinct()->orderBy('category')->pluck('category');
        foreach ($enUso as $clave) {
            DB::table($entradas)->insert([
                'domain' => 'client_segment', 'key' => $clave, 'label' => ucfirst(str_replace('_', ' ', (string) $clave)), 'sort_order' => $orden++,
                'is_active' => true, 'is_system' => false, 'props' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table(Tenant::current()?->slug.'_auth.catalog_entries')->where('domain', 'client_segment')->delete();

        $subClients = $this->table('sub_clients');
        $fueraDeLista = DB::table($subClients)->whereNotNull('category')->whereNotIn('category', array_keys(self::SISTEMA))->exists();
        if (! $fueraDeLista) {
            DB::statement("ALTER TABLE {$subClients} ADD CONSTRAINT sub_clients_category_check CHECK (category IN ('a_walkin','b_architect_designer','c_bidding','d_referred'))");
        }
    }
};
