<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ClienteRow;
use App\Modules\Legacy\Data\ContactoClienteRow;
use App\Modules\Legacy\Data\ExtractedClientes;
use App\Modules\Legacy\Models\Cliente;
use App\Modules\Legacy\Models\ContactoCliente;
use App\Modules\Legacy\Support\RowHasher;
use App\Modules\Legacy\Support\TaxIdNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `ClienteStagingLoader` (que NO se modifica, sigue siendo el
 * camino full-sync de `legacy:sync-clientes`). Mismo patrón de hash-compare que
 * `ProductoIncrementalStagingLoader` (ver ese docblock) — `INSERT ... ON CONFLICT ... DO UPDATE
 * ... WHERE source_hash IS DISTINCT FROM EXCLUDED.source_hash`.
 *
 * `changedCodclientes()` del resultado es la lista de `icg_codcliente` cuya fila de `clientes`
 * cambió de verdad — es lo único que necesita `ClienteMatcher::match()` después (el paso caro de
 * esta entidad: reconstruye 2 índices completos de `ventas_diseno.sub_clients`/
 * `sub_client_contacts` y recorre cada cliente). Los contactos (`contactos_cliente`) NO alimentan
 * al Matcher (que solo mira `cliente->cif`/`cliente->email`, columnas de la propia `clientes`, no
 * de sus contactos) — así que su hash-compare solo ahorra escritura de staging, no dispara nada
 * más, y no aportan a `changed_codclientes`.
 *
 * SCRUM-1137 (Fase 2): `nif20` entra al hash de cambio (y `ruc`/`ruc_dv`/`ruc_origen`/`ruc_estado`
 * se calculan con `TaxIdNormalizer` y se persisten). ATENCIÓN OPERATIVA: al cambiar el contenido del
 * hash, el `source_hash` de TODAS las filas existentes deja de coincidir, así que la primera corrida
 * incremental tras el deploy vería ~100 % de clientes cambiados, superaría el umbral del circuit
 * breaker (20 %) y quedaría `held_for_review`. Decisión de Luis: NO se toca el breaker ni se agrega
 * ninguna bandera; el re-baseline se resuelve operativamente tras el deploy, en cada entorno.
 * Nota verificada leyendo el código: la carga completa (`ClienteStagingLoader`, `legacy:sync-clientes`)
 * puebla `nif20`/`ruc*` pero NO escribe `source_hash`, así que por sí sola no re-baselinea; en cambio
 * este loader (incluso con `--dry-run` del comando) actualiza el `source_hash` de toda fila cambiada
 * ANTES de evaluar el breaker, de modo que la primera corrida incremental (aunque quede
 * `held_for_review`) deja el hash nuevo y la siguiente corre normal.
 */
final class ClienteIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{
     *   clientes: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   contactos: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   changed_codclientes: list<int>,
     * }
     */
    public function load(ExtractedClientes $extracted): array
    {
        $now = now();

        return DB::transaction(function () use ($extracted, $now): array {
            [$clientes, $changedCodclientes] = $this->upsertClientes($extracted->clientes, $now);

            $clienteIdsByCodigo = $this->resolveClienteIds($extracted->icgCodclientes());
            [$contactos] = $this->upsertContactos($extracted->contactos, $clienteIdsByCodigo, $now);

            sort($changedCodclientes);

            return [
                'clientes'  => $clientes,
                'contactos' => $contactos,
                'changed_codclientes' => $changedCodclientes,
            ];
        });
    }

    /**
     * @param  list<ClienteRow> $rows
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>}
     */
    private function upsertClientes(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table    = (new Cliente())->getTable();
        $nuevos   = 0;
        $cambiados = 0;
        $changed  = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (ClienteRow $row) use ($now): array {
                $hash = RowHasher::hash([
                    'nombre_comercial' => $row->nombreComercial,
                    'razon_social'     => $row->razonSocial,
                    'cif'              => $row->cif,
                    'nif20'            => $row->nif20,
                    'direccion'        => $row->direccion,
                    'poblacion'        => $row->poblacion,
                    'provincia'        => $row->provincia,
                    'pais'             => $row->pais,
                    'telefono1'        => $row->telefono1,
                    'telefono2'        => $row->telefono2,
                    'mobil'            => $row->mobil,
                    'email'            => $row->email,
                    'icg_codvendedor'  => $row->icgCodvendedor,
                    'bloqueado'        => $row->bloqueado,
                ]);

                $tax = TaxIdNormalizer::normalize($row->nif20, $row->cif);

                return [
                    $row->icgCodcliente, $row->nombreComercial, $row->razonSocial, $row->cif,
                    $row->nif20, $tax->ruc, $tax->dv, $tax->origen, $tax->estado,
                    $row->direccion, $row->poblacion, $row->provincia, $row->pais, $row->telefono1,
                    $row->telefono2, $row->mobil, $row->email, $row->icgCodvendedor, $row->bloqueado,
                    $row->fechaModificadoIcg?->format('Y-m-d H:i:s'), $hash, $now, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                keyColumn: 'icg_codcliente',
                columns: [
                    'icg_codcliente', 'nombre_comercial', 'razon_social', 'cif', 'nif20', 'ruc', 'ruc_dv',
                    'ruc_origen', 'ruc_estado', 'direccion',
                    'poblacion', 'provincia', 'pais', 'telefono1', 'telefono2', 'mobil', 'email',
                    'icg_codvendedor', 'bloqueado', 'fecha_modificado_icg', 'source_hash',
                    'created_at', 'updated_at',
                ],
                values: $values,
                conflictColumns: ['icg_codcliente'],
                updateColumns: [
                    'nombre_comercial', 'razon_social', 'cif', 'nif20', 'ruc', 'ruc_dv', 'ruc_origen',
                    'ruc_estado', 'direccion', 'poblacion', 'provincia',
                    'pais', 'telefono1', 'telefono2', 'mobil', 'email', 'icg_codvendedor',
                    'bloqueado', 'fecha_modificado_icg', 'source_hash', 'updated_at',
                ],
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
     * Idéntico a `ClienteStagingLoader::resolveClienteIds()` — puro read, sin hash, se reusa tal
     * cual porque no tiene nada que "incrementar": siempre necesita el mapeo completo de esta
     * corrida para resolver `legacy_cliente_id` de los contactos.
     *
     * @param  list<int>       $icgCodclientes
     * @return array<int, int> icg_codcliente => id interno
     */
    private function resolveClienteIds(array $icgCodclientes): array
    {
        if ($icgCodclientes === []) {
            return [];
        }

        /** @var array<int, int> $map */
        $map = [];
        foreach (array_chunk($icgCodclientes, self::CHUNK_SIZE) as $chunk) {
            $map += Cliente::query()
                ->whereIn('icg_codcliente', $chunk)
                ->pluck('id', 'icg_codcliente')
                ->all();
        }

        return $map;
    }

    /**
     * @param  list<ContactoClienteRow> $rows
     * @param  array<int, int>          $clienteIdsByCodigo icg_codcliente => id interno
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>}
     */
    private function upsertContactos(array $rows, array $clienteIdsByCodigo, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table    = (new ContactoCliente())->getTable();
        $nuevos   = 0;
        $cambiados = 0;
        $processed = 0;

        $rowsWithCliente = array_values(array_filter(
            $rows,
            static fn (ContactoClienteRow $row): bool => isset($clienteIdsByCodigo[$row->icgCodcliente]),
        ));

        foreach (array_chunk($rowsWithCliente, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (ContactoClienteRow $row) use ($clienteIdsByCodigo, $now): array {
                $hash = RowHasher::hash([
                    'cargo'    => $row->cargo,
                    'nombre'   => $row->nombre,
                    'telefono' => $row->telefono,
                    'mobil'    => $row->mobil,
                    'email'    => $row->email,
                ]);

                return [
                    $clienteIdsByCodigo[$row->icgCodcliente], $row->icgId, $row->cargo, $row->nombre,
                    $row->telefono, $row->mobil, $row->email, $hash, $now, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                keyColumn: 'icg_id',
                columns: ['legacy_cliente_id', 'icg_id', 'cargo', 'nombre', 'telefono', 'mobil', 'email', 'source_hash', 'created_at', 'updated_at'],
                values: $values,
                conflictColumns: ['icg_id'],
                updateColumns: ['legacy_cliente_id', 'cargo', 'nombre', 'telefono', 'mobil', 'email', 'source_hash', 'updated_at'],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
            }
            $processed += count($chunk);
        }

        return [
            ['total' => $processed, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $processed - $nuevos - $cambiados],
            [],
        ];
    }

    /**
     * Mismo mecanismo que `ProductoIncrementalStagingLoader::upsertChunk()` — ver ese docblock
     * para el detalle de `xmax = 0` y por qué `RETURNING` alcanza sin una segunda consulta.
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
