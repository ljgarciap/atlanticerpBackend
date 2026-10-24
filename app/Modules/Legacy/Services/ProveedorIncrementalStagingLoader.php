<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedProveedores;
use App\Modules\Legacy\Data\ProveedorRow;
use App\Modules\Legacy\Models\Proveedor;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `ProveedorStagingLoader` (que NO se modifica). Proveedores
 * es la entidad más simple del módulo: sin Matcher, sin Loader-B (ver docblock de
 * `LegacySyncProveedoresCommand`) — el staging ES el pipeline completo, así que a diferencia de
 * Productos/Clientes acá no hace falta un "changed_*" separado para filtrar un paso caro
 * posterior: el hash-compare de esta clase YA es el ahorro completo que pide el ticket.
 */
final class ProveedorIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{total: int, nuevos: int, cambiados: int, sin_cambios: int}
     */
    public function load(ExtractedProveedores $extracted): array
    {
        $now = now();

        return DB::transaction(fn (): array => $this->upsertProveedores($extracted->proveedores, $now));
    }

    /**
     * @param  list<ProveedorRow> $rows
     * @return array{total: int, nuevos: int, cambiados: int, sin_cambios: int}
     */
    private function upsertProveedores(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return ['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0];
        }

        $table    = (new Proveedor())->getTable();
        $nuevos   = 0;
        $cambiados = 0;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (ProveedorRow $row) use ($now): array {
                $hash = RowHasher::hash([
                    'nombre'           => $row->nombre,
                    'nombre_comercial' => $row->nombreComercial,
                    'cif'              => $row->cif,
                    'direccion'        => $row->direccion,
                    'telefono1'        => $row->telefono1,
                    'email'            => $row->email,
                ]);

                return [
                    $row->icgCodproveedor, $row->nombre, $row->nombreComercial, $row->cif,
                    $row->direccion, $row->telefono1, $row->email, $hash, $now, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                columns: ['icg_codproveedor', 'nombre', 'nombre_comercial', 'cif', 'direccion', 'telefono1', 'email', 'source_hash', 'created_at', 'updated_at'],
                values: $values,
                conflictColumns: ['icg_codproveedor'],
                updateColumns: ['nombre', 'nombre_comercial', 'cif', 'direccion', 'telefono1', 'email', 'source_hash', 'updated_at'],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
            }
        }

        $total = count($rows);

        return ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados];
    }

    /**
     * Mismo mecanismo que `ProductoIncrementalStagingLoader::upsertChunk()` — ver ese docblock.
     * `provider_id`/`match_status` (resueltos por `ProveedorFusionLoader`, no por este Loader-A)
     * NUNCA se incluyen en `updateColumns` — mismo criterio que `catalog_product_id`/
     * `sub_client_id` en las otras entidades, una re-corrida nunca resetea lo que puso el fusion
     * loader.
     *
     * @param  list<string> $columns
     * @param  list<list<mixed>> $values
     * @param  list<string> $conflictColumns
     * @param  list<string> $updateColumns
     * @return list<object{inserted: bool}>
     */
    private function upsertChunk(string $table, array $columns, array $values, array $conflictColumns, array $updateColumns): array
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
            RETURNING (xmax = 0) AS inserted
            SQL;

        $bindings = array_merge(...$values);

        /** @var list<object{inserted: bool}> $result */
        $result = DB::select($sql, $bindings);

        return $result;
    }
}
