<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedProductos;
use App\Modules\Legacy\Data\ProductoCostoRow;
use App\Modules\Legacy\Data\ProductoPrecioRow;
use App\Modules\Legacy\Data\ProductoRow;
use App\Modules\Legacy\Models\Producto;
use App\Modules\Legacy\Models\ProductoCosto;
use App\Modules\Legacy\Models\ProductoPrecio;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `ProductoStagingLoader` (que NO se modifica, sigue siendo
 * el camino full-sync de `legacy:sync-productos`). Misma forma de datos, mismas 3 tablas, pero el
 * upsert usa `ON CONFLICT ... DO UPDATE ... WHERE source_hash IS DISTINCT FROM EXCLUDED.source_hash`
 * — Postgres directamente NO toca la fila cuando el hash no cambió (ni siquiera un UPDATE
 * no-op), en vez de comparar en PHP fila por fila antes de decidir si upsertear. `RETURNING`
 * permite distinguir insert/update reales de filas que Postgres saltó por el WHERE, sin una
 * segunda consulta.
 *
 * `changedCodarticulos()` del resultado es la unión de artículos que cambiaron en CUALQUIERA de
 * las 3 tablas (productos/precios/costos) — un artículo cuyo `producto` no cambió pero sí su
 * `precio` igual necesita pasar por el Mapper/CatalogProductLoader (bug real corregido 2026-09-14:
 * la primera versión solo evitaba el write de staging, pero seguía mandando TODOS los artículos
 * extraídos al Mapper sin importar si cambiaron — el ahorro real que pide el ticket es que las
 * filas sin cambio ni siquiera lleguen ahí).
 *
 * Por qué hash y no ventana de fecha: ver App\Modules\Legacy\Support\RowHasher y
 * Illumination/migracion-icg-diseno.md, ticket SCRUM-838.
 */
final class ProductoIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{
     *   productos: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   precios: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   costos: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   changed_codarticulos: list<int>,
     * }
     */
    public function load(ExtractedProductos $extracted): array
    {
        $now = now();

        return DB::transaction(function () use ($extracted, $now): array {
            [$productos, $productosChanged] = $this->upsertProductos($extracted->productos, $now);
            [$precios, $preciosChanged]     = $this->upsertPrecios($extracted->precios, $now);
            [$costos, $costosChanged]       = $this->upsertCostos($extracted->costos, $now);

            $changed = array_values(array_unique(array_merge($productosChanged, $preciosChanged, $costosChanged)));
            sort($changed);

            return [
                'productos' => $productos,
                'precios'   => $precios,
                'costos'    => $costos,
                'changed_codarticulos' => $changed,
            ];
        });
    }

    /**
     * @param  list<ProductoRow> $rows
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>}
     */
    private function upsertProductos(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table    = (new Producto())->getTable();
        $nuevos   = 0;
        $cambiados = 0;
        $changed  = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (ProductoRow $row) use ($now): array {
                // SCRUM-921 — `stock_al4` NUNCA entra al hash de cambio: AL4 es un almacén en
                // vivo, su stock fluctúa muchísimo más seguido que los campos de catálogo. Si
                // participara acá, una corrida normal marcaría "cambiado" a casi todo el catálogo
                // activo solo por movimiento de inventario, disparando el circuit breaker del 20%
                // en cada corrida real (regresión sobre el incremental ya en producción desde
                // SCRUM-838). Sigue viajando en `values`/`updateColumns` para refrescarse gratis
                // cuando la fila de todos modos se actualiza por un cambio real de negocio — ver
                // docblock de la clase.
                $hash = RowHasher::hash([
                    'descripcion'    => $row->descripcion,
                    'familia_nombre' => $row->familiaNombre,
                    'marca_nombre'   => $row->marcaNombre,
                    'ref_proveedor'  => $row->refProveedor,
                    'descatalogado'  => $row->descatalogado,
                ]);

                return [
                    $row->icgCodarticulo, $row->descripcion, $row->familiaNombre, $row->marcaNombre,
                    $row->refProveedor, $row->descatalogado, $row->stockAl4, $hash, $now, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                keyColumn: 'icg_codarticulo',
                columns: ['icg_codarticulo', 'descripcion', 'familia_nombre', 'marca_nombre', 'ref_proveedor', 'descatalogado', 'stock_al4', 'source_hash', 'created_at', 'updated_at'],
                values: $values,
                conflictColumns: ['icg_codarticulo'],
                updateColumns: ['descripcion', 'familia_nombre', 'marca_nombre', 'ref_proveedor', 'descatalogado', 'stock_al4', 'source_hash', 'updated_at'],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
                $changed[] = (int) $r->key_value;
            }
        }

        $total = count($rows);

        return [
            ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados],
            $changed,
        ];
    }

    /**
     * @param  list<ProductoPrecioRow> $rows
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>}
     */
    private function upsertPrecios(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table    = (new ProductoPrecio())->getTable();
        $nuevos   = 0;
        $cambiados = 0;
        $changed  = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (ProductoPrecioRow $row) use ($now): array {
                $hash = RowHasher::hash([
                    'precio_neto'        => $row->precioNeto,
                    'precio_bruto'       => $row->precioBruto,
                    'descuento'          => $row->descuento,
                    'descatalogado_fila' => $row->descatalogadoFila,
                ]);

                return [
                    $row->icgCodarticulo, $row->idtarifav, $row->precioNeto, $row->precioBruto,
                    $row->descuento, $row->descatalogadoFila, $hash, $now, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                keyColumn: 'icg_codarticulo',
                columns: ['icg_codarticulo', 'idtarifav', 'precio_neto', 'precio_bruto', 'descuento', 'descatalogado_fila', 'source_hash', 'created_at', 'updated_at'],
                values: $values,
                conflictColumns: ['icg_codarticulo', 'idtarifav'],
                updateColumns: ['precio_neto', 'precio_bruto', 'descuento', 'descatalogado_fila', 'source_hash', 'updated_at'],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
                $changed[] = (int) $r->key_value;
            }
        }

        $total = count($rows);

        return [
            ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados],
            $changed,
        ];
    }

    /**
     * @param  list<ProductoCostoRow> $rows
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>}
     */
    private function upsertCostos(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table    = (new ProductoCosto())->getTable();
        $nuevos   = 0;
        $cambiados = 0;
        $changed  = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (ProductoCostoRow $row) use ($now): array {
                $hash = RowHasher::hash(['costo_neto' => $row->costoNeto]);

                return [$row->icgCodarticulo, $row->codproveedor, $row->costoNeto, $hash, $now, $now];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                keyColumn: 'icg_codarticulo',
                columns: ['icg_codarticulo', 'codproveedor', 'costo_neto', 'source_hash', 'created_at', 'updated_at'],
                values: $values,
                conflictColumns: ['icg_codarticulo', 'codproveedor'],
                updateColumns: ['costo_neto', 'source_hash', 'updated_at'],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
                $changed[] = (int) $r->key_value;
            }
        }

        $total = count($rows);

        return [
            ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados],
            $changed,
        ];
    }

    /**
     * INSERT ... ON CONFLICT DO UPDATE ... WHERE source_hash IS DISTINCT FROM EXCLUDED.source_hash
     * RETURNING icg_codarticulo, (xmax = 0) AS inserted — `xmax = 0` es el truco estándar de
     * Postgres para saber si la fila RETURNING vino de un INSERT (xmax nunca seteado) o de un
     * UPDATE real (xmax seteado a la transacción que la tocó). Una fila que el WHERE frenó
     * simplemente no aparece en RETURNING — no hace falta distinguirla explícitamente, su
     * ausencia ya lo dice. `keyColumn` siempre se llama `icg_codarticulo` en las 3 tablas de
     * Productos — parametrizado igual para que otras entidades (Clientes, Cotizaciones, etc.)
     * puedan reusar este método con su propia columna de "a qué entidad de negocio pertenece".
     *
     * @param  list<string> $columns
     * @param  list<list<mixed>> $values
     * @param  list<string> $conflictColumns
     * @param  list<string> $updateColumns
     * @return list<object{key_value: int, inserted: bool}>
     */
    private function upsertChunk(string $table, string $keyColumn, array $columns, array $values, array $conflictColumns, array $updateColumns): array
    {
        $columnList   = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $valuesSql    = implode(', ', array_fill(0, count($values), "({$placeholders})"));
        $conflictSql  = implode(', ', $conflictColumns);
        $updateSetSql = implode(', ', array_map(static fn (string $c): string => "{$c} = EXCLUDED.{$c}", $updateColumns));

        $sql = <<<SQL
            INSERT INTO {$table} ({$columnList})
            VALUES {$valuesSql}
            ON CONFLICT ({$conflictSql}) DO UPDATE SET {$updateSetSql}
            WHERE {$table}.source_hash IS DISTINCT FROM EXCLUDED.source_hash
            RETURNING {$keyColumn} AS key_value, (xmax = 0) AS inserted
            SQL;

        $bindings = array_merge(...$values);

        /** @var list<object{key_value: int, inserted: bool}> $result */
        $result = DB::select($sql, $bindings);

        return $result;
    }
}
