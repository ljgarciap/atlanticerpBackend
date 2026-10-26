<?php

declare(strict_types=1);

use App\Shared\Multitenancy\Tenant;
use App\Shared\Multitenancy\TenantSchemaMigration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 5b-2 (docs/specs/fase5b-eliminar-legacy-lafayette.md) — retira el esquema que dejaron Legacy (ICG) y Lafayette.
 *
 * Borra: las 32 tablas del schema `{tenant}_legacy` (migración desde ICG; el schema queda vacío), `lafayette_productos` y `lafayette_sync_runs`,
 * las columnas «gemelo ICG» de `orders` (`origin`, `icg_*`, `quote_id`, `sub_client_id`) y `quotes.origin`, las columnas
 * `external_*` de `catalog_products`, `warehouses.solo_consulta`/`mostrar_sin_stock` con su trigger, la bodega de consulta externa
 * «Zona Libre» y el ajuste `lafayette_guarda_min_ratio`.
 *
 * Seguridad de datos (IRREVERSIBLE: `down()` no restaura nada):
 *  - En cualquier entorno aborta si la bodega de solo consulta está referenciada por stock, movimientos, ubicaciones, pedidos, etc.
 *  - Fuera de local/dev/testing aborta también si alguna tabla de Legacy/Lafayette tiene filas (salvo los ajustes por defecto de
 *    `stock_sync_settings`), si hay pedidos/cotizaciones gemelas o si algún producto trae `external_source`: exportar antes.
 */
return new class extends TenantSchemaMigration
{
    protected string $module = 'bodega';

    /** Entornos donde los datos de estas tablas son de demostración y se pueden descartar. */
    private const ENTORNOS_DESCARTABLES = ['local', 'dev', 'testing'];

    public function up(): void
    {
        $slug    = (string) Tenant::current()?->slug;
        $bodega  = "{$slug}_bodega";
        $diseno  = "{$slug}_ventas_diseno";
        $legacy  = "{$slug}_legacy";
        $auth    = "{$slug}_auth";

        $this->verificarBodegaDeConsulta($bodega);
        if (! app()->environment(self::ENTORNOS_DESCARTABLES)) {
            $this->verificarSinDatos($bodega, $diseno, $legacy);
        }

        // Trigger y función que impedían stock en bodegas de solo consulta.
        DB::statement("DROP TRIGGER IF EXISTS product_warehouse_stock_solo_consulta ON {$bodega}.product_warehouse_stock");
        DB::statement("DROP FUNCTION IF EXISTS {$bodega}.reject_stock_en_bodega_solo_consulta()");

        // La bodega «Zona Libre» de consulta externa (la columna aún existe en este punto).
        if ($this->tieneColumna($bodega, 'warehouses', 'solo_consulta')) {
            DB::statement("DELETE FROM {$bodega}.warehouses WHERE solo_consulta = true");
        }
        DB::statement("ALTER TABLE {$bodega}.warehouses DROP COLUMN IF EXISTS solo_consulta, DROP COLUMN IF EXISTS mostrar_sin_stock");

        // Campos «gemelo ICG» de los pedidos de Bodega (un DROP COLUMN arrastra sus índices, FKs y CHECK).
        DB::statement("ALTER TABLE {$bodega}.orders DROP CONSTRAINT IF EXISTS orders_icg_fields_check");
        DB::statement("ALTER TABLE {$bodega}.orders DROP CONSTRAINT IF EXISTS orders_origin_check");
        DB::statement("DROP INDEX IF EXISTS {$bodega}.orders_icg_key_uq");
        DB::statement(
            "ALTER TABLE {$bodega}.orders DROP COLUMN IF EXISTS origin, DROP COLUMN IF EXISTS icg_serie, DROP COLUMN IF EXISTS icg_numero, "
            .'DROP COLUMN IF EXISTS icg_facturado, DROP COLUMN IF EXISTS icg_total_neto, DROP COLUMN IF EXISTS icg_hoja, '
            .'DROP COLUMN IF EXISTS icg_vendedor, DROP COLUMN IF EXISTS quote_id, DROP COLUMN IF EXISTS sub_client_id'
        );

        // Origen de las cotizaciones (la gemela de ICG era el único valor distinto de «comercial»).
        DB::statement("ALTER TABLE {$diseno}.quotes DROP CONSTRAINT IF EXISTS quotes_origin_check");
        DB::statement("DROP INDEX IF EXISTS {$diseno}.{$slug}_ventas_diseno_quotes_origin_index");
        DB::statement("ALTER TABLE {$diseno}.quotes DROP COLUMN IF EXISTS origin");

        // Procedencia de los productos importados de una fuente externa.
        DB::statement("ALTER TABLE {$diseno}.catalog_products DROP COLUMN IF EXISTS external_source, DROP COLUMN IF EXISTS external_synced_at");

        // Tablas de Lafayette y el schema de Legacy completo.
        DB::statement("DROP TABLE IF EXISTS {$bodega}.lafayette_productos");
        DB::statement("DROP TABLE IF EXISTS {$diseno}.lafayette_sync_runs");
        // Las tablas del schema de Legacy; el schema queda vacío porque `Tenant::schemas()` y las migraciones históricas aún lo usan.
        foreach (DB::table('information_schema.tables')->where('table_schema', $legacy)->pluck('table_name') as $tabla) {
            DB::statement("DROP TABLE IF EXISTS {$legacy}.\"{$tabla}\" CASCADE");
        }

        DB::statement("DELETE FROM {$auth}.system_settings WHERE key = 'lafayette_guarda_min_ratio'");
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Fase 5b-2: migración irreversible (borra el esquema de ICG/Lafayette y los campos «gemelo ICG»). Restaurar desde un respaldo de la base.'
        );
    }

    private function tieneColumna(string $schema, string $tabla, string $columna): bool
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $schema)->where('table_name', $tabla)->where('column_name', $columna)->exists();
    }

    /** Aborta (en cualquier entorno) si algo referencia la bodega de solo consulta: no se borra una bodega con historia. */
    private function verificarBodegaDeConsulta(string $bodega): void
    {
        if (! $this->tieneColumna($bodega, 'warehouses', 'solo_consulta')) {
            return;
        }

        $ids = DB::table("{$bodega}.warehouses")->where('solo_consulta', true)->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $referencias = DB::select(
            "SELECT c.conrelid::regclass::text AS tabla, a.attname AS columna
               FROM pg_constraint c
               JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
              WHERE c.contype = 'f' AND c.confrelid = ?::regclass",
            ["{$bodega}.warehouses"]
        );

        foreach ($referencias as $ref) {
            $usos = (int) DB::table($ref->tabla)->whereIn($ref->columna, $ids)->count();
            if ($usos > 0) {
                throw new RuntimeException("La bodega de solo consulta está referenciada por {$usos} fila(s) de {$ref->tabla}.{$ref->columna}: resolverlo antes de retirarla (esta migración no borra historia).");
            }
        }
    }

    /** Fuera de los entornos descartables: nada de Legacy/Lafayette/«gemelo ICG» puede tener datos. */
    private function verificarSinDatos(string $bodega, string $diseno, string $legacy): void
    {
        $tablas = DB::table('information_schema.tables')->where('table_schema', $legacy)->pluck('table_name')->all();
        foreach ($tablas as $tabla) {
            $filas = (int) DB::table("{$legacy}.{$tabla}")->count();
            if ($tabla === 'stock_sync_settings' ? $filas > 1 : $filas > 0) {
                throw new RuntimeException("{$legacy}.{$tabla} tiene {$filas} fila(s): exportarlas antes de retirar el esquema de ICG.");
            }
        }

        foreach ([["{$bodega}.lafayette_productos", 'lafayette_productos'], ["{$diseno}.lafayette_sync_runs", 'lafayette_sync_runs']] as [$completa, $nombre]) {
            if (DB::table('information_schema.tables')->where('table_name', $nombre)->exists() && DB::table($completa)->count() > 0) {
                throw new RuntimeException("{$completa} tiene filas: exportarlas antes de retirar la integración con Lafayette.");
            }
        }

        $porColumna = [
            [$bodega, 'orders', 'origin', "origin = 'icg_legacy'"],
            [$diseno, 'quotes', 'origin', "origin = 'icg_legacy'"],
            [$diseno, 'catalog_products', 'external_source', 'external_source IS NOT NULL'],
        ];
        foreach ($porColumna as [$schema, $tabla, $columna, $condicion]) {
            if ($this->tieneColumna($schema, $tabla, $columna) && DB::table("{$schema}.{$tabla}")->whereRaw($condicion)->exists()) {
                throw new RuntimeException("{$schema}.{$tabla} tiene filas con {$condicion}: resolverlas antes de retirar los campos «gemelo ICG» / de procedencia externa.");
            }
        }
    }
};
