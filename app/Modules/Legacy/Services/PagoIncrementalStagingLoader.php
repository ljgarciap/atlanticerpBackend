<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\PagoRow;
use App\Modules\Legacy\Models\Pago;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `PagoStagingLoader` (que NO se modifica). Pagos no tiene
 * Matcher ni Loader-B (TESORERIA no referencia cliente/factura, ver docblock de
 * `App\Modules\Legacy\Data\ExtractedPagos`) — el hash-compare del staging ES el ahorro completo
 * para esta entidad, no hay un "paso caro" posterior que filtrar (a diferencia de
 * Productos/Facturas). No expone una lista de "claves cambiadas" porque nada consume esa lista.
 *
 * Paginado igual que `PagoStagingLoader::loadPaginated()` (mismo motivo real: 144,194 filas de
 * TESORERIA revientan por OOM si se traen enteras — ver docblock de `PagoExtractor`) — nunca
 * materializa más de una página en memoria a la vez, misma atomicidad (una transacción para toda
 * la corrida).
 */
final class PagoIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  callable(int, int): list<PagoRow> $pageFetcher ($offset, $limit) => página, [] = fin
     * @return array{total: int, nuevos: int, cambiados: int, sin_cambios: int}
     */
    public function loadPaginated(callable $pageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($pageFetcher, $pageSize): array {
            $now      = now();
            $total    = 0;
            $nuevos   = 0;
            $cambiados = 0;
            $offset   = 0;

            while (true) {
                /** @var list<PagoRow> $page */
                $page = $pageFetcher($offset, $pageSize);
                if ($page === []) {
                    break;
                }

                [$pageNuevos, $pageCambiados] = $this->upsertPagos($page, $now);
                $total += count($page);
                $nuevos += $pageNuevos;
                $cambiados += $pageCambiados;

                if (count($page) < $pageSize) {
                    break;
                }

                $offset += $pageSize;
            }

            return ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados];
        });
    }

    /**
     * @param  list<PagoRow> $rows
     * @return array{0: int, 1: int} [nuevos, cambiados]
     */
    private function upsertPagos(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return [0, 0];
        }

        $table    = (new Pago())->getTable();
        $nuevos   = 0;
        $cambiados = 0;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (PagoRow $row) use ($now): array {
                $hash = RowHasher::hash([
                    'fecha_documento'   => $row->fechaDocumento?->format('Y-m-d H:i:s'),
                    'fecha_vencimiento' => $row->fechaVencimiento?->format('Y-m-d H:i:s'),
                    'importe'           => $row->importe,
                    'icg_codformapago'  => $row->icgCodformapago,
                    'estado'            => $row->estado,
                    // FECHASALDADO puede setearse mucho después de FECHADOCUMENTO cuando un pago
                    // se concilia — tiene que estar en el hash para que esa actualización tardía
                    // no quede invisible para este comando (mismo espíritu que fe_* en Facturas).
                    'fecha_saldado'     => $row->fechaSaldado?->format('Y-m-d H:i:s'),
                ]);

                return [
                    $row->icgOrigen, $row->icgTipodocumento, $row->icgSerie, $row->icgNumero,
                    $row->icgN, $row->icgPosicion,
                    $row->fechaDocumento?->format('Y-m-d H:i:s'), $row->fechaVencimiento?->format('Y-m-d H:i:s'),
                    $row->importe, $row->icgCodformapago, $row->estado,
                    $row->fechaSaldado?->format('Y-m-d H:i:s'),
                    $hash, $now, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                columns: [
                    'icg_origen', 'icg_tipodocumento', 'icg_serie', 'icg_numero', 'icg_n', 'icg_posicion',
                    'fecha_documento', 'fecha_vencimiento', 'importe', 'icg_codformapago', 'estado',
                    'fecha_saldado', 'source_hash', 'created_at', 'updated_at',
                ],
                values: $values,
                conflictColumns: ['icg_origen', 'icg_tipodocumento', 'icg_serie', 'icg_numero', 'icg_n', 'icg_posicion'],
                updateColumns: [
                    'fecha_documento', 'fecha_vencimiento', 'importe', 'icg_codformapago', 'estado',
                    'fecha_saldado', 'source_hash', 'updated_at',
                ],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
            }
        }

        return [$nuevos, $cambiados];
    }

    /**
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
