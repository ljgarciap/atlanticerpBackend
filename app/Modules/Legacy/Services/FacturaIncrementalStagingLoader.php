<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedFacturas;
use App\Modules\Legacy\Data\FacturaRow;
use App\Modules\Legacy\Models\Factura;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `FacturaStagingLoader` (que NO se modifica, sigue siendo el
 * camino full-sync de `legacy:sync-facturas`). Mismo patrón que `ProductoIncrementalStagingLoader`:
 * `INSERT ... ON CONFLICT ... DO UPDATE ... WHERE source_hash IS DISTINCT FROM EXCLUDED.source_hash
 * RETURNING id, (xmax = 0) AS inserted` — Postgres no toca la fila si el hash no cambió.
 *
 * El hash incluye los campos fe_* (Facturación Electrónica, SCRUM-841) además de los campos de
 * negocio de la cabecera — una factura puede autorizarse ante la DGI días/semanas después de
 * emitida sin que cambie ningún otro campo; si el hash no cubriera fe_*, esa actualización tardía
 * quedaría invisible para este comando y nunca llegaría al Matcher (mismo espíritu que el fix de
 * "precio cambia sin que cambie el producto" en Productos).
 *
 * `legacy_cliente_id`/`orphan` (los resuelve `FacturaMatcher`, no este Loader) NUNCA entran al
 * `$update` — mismo criterio que `FacturaStagingLoader` full-sync, para no resetear el resultado
 * de un Matcher anterior en cada corrida.
 *
 * Devuelve los `id` internos de las facturas que cambiaron de verdad — son las únicas que el
 * comando manda al Matcher después (ver LegacySyncIncrementalFacturasCommand).
 */
final class FacturaIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @return array{
     *   facturas: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   changed_ids: list<int>,
     * }
     */
    public function load(ExtractedFacturas $extracted): array
    {
        $now = now();

        return DB::transaction(function () use ($extracted, $now): array {
            [$counts, $changedIds] = $this->upsertFacturas($extracted->facturas, $now);

            return ['facturas' => $counts, 'changed_ids' => $changedIds];
        });
    }

    /**
     * @param  list<FacturaRow> $rows
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>}
     */
    private function upsertFacturas(array $rows, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table    = (new Factura())->getTable();
        $nuevos   = 0;
        $cambiados = 0;
        $changed  = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (FacturaRow $row) use ($now): array {
                $hash = RowHasher::hash([
                    'icg_codcliente'  => $row->icgCodcliente,
                    'icg_codvendedor' => $row->icgCodvendedor,
                    'fecha'           => $row->fecha?->format('Y-m-d H:i:s'),
                    'total_neto'      => $row->totalNeto,
                    'total_impuestos' => $row->totalImpuestos,
                    'total_bruto'     => $row->totalBruto,
                    'sufactura'       => $row->sufactura,
                    'fe_qr'              => $row->feQr,
                    'fe_auth_number'     => $row->feAuthNumber,
                    'fe_serie'           => $row->feSerie,
                    'fe_numero'          => $row->feNumero,
                    'fe_fecha_emision'   => $row->feFechaEmision,
                    'fe_fecha_recepcion' => $row->feFechaRecepcion,
                    'fe_error'           => $row->feError,
                    'fe_estado'          => $row->feEstado,
                ]);

                return [
                    $row->icgNumserie, $row->icgNumfactura, $row->icgN,
                    $row->icgCodcliente, $row->icgCodvendedor,
                    $row->fecha?->format('Y-m-d H:i:s'), $row->totalNeto, $row->totalImpuestos,
                    $row->totalBruto, $row->sufactura,
                    $row->feQr, $row->feAuthNumber, $row->feSerie, $row->feNumero,
                    $row->feFechaEmision, $row->feFechaRecepcion, $row->feError, $row->feEstado,
                    $hash, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                columns: [
                    'icg_numserie', 'icg_numfactura', 'icg_n', 'icg_codcliente', 'icg_codvendedor',
                    'fecha', 'total_neto', 'total_impuestos', 'total_bruto', 'sufactura',
                    'fe_qr', 'fe_auth_number', 'fe_serie', 'fe_numero',
                    'fe_fecha_emision', 'fe_fecha_recepcion', 'fe_error', 'fe_estado',
                    'source_hash', 'created_at',
                ],
                values: $values,
                conflictColumns: ['icg_numserie', 'icg_numfactura', 'icg_n'],
                updateColumns: [
                    'icg_codcliente', 'icg_codvendedor', 'fecha', 'total_neto', 'total_impuestos',
                    'total_bruto', 'sufactura',
                    'fe_qr', 'fe_auth_number', 'fe_serie', 'fe_numero',
                    'fe_fecha_emision', 'fe_fecha_recepcion', 'fe_error', 'fe_estado', 'source_hash',
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
     * @param  list<string> $columns
     * @param  list<list<mixed>> $values
     * @param  list<string> $conflictColumns
     * @param  list<string> $updateColumns
     * @return list<object{key_value: int, inserted: bool}>
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
            RETURNING id AS key_value, (xmax = 0) AS inserted
            SQL;

        $bindings = array_merge(...$values);

        /** @var list<object{key_value: int, inserted: bool}> $result */
        $result = DB::select($sql, $bindings);

        return $result;
    }
}
