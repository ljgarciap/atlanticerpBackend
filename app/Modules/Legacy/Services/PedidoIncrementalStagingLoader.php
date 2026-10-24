<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedPedidos;
use App\Modules\Legacy\Data\PedidoItemRow;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\PedidoItem;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `PedidoStagingLoader` (que NO se modifica). Mismo patrón
 * exacto que `CotizacionIncrementalStagingLoader` (leer su docblock primero) — PEDVENTALIN
 * tampoco tiene llave propia, el hash de cada línea se pliega (XOR, orden-independiente) en el
 * `source_hash` de su cabecera (`icg_numserie`+`icg_numpedido`+`icg_n`, 3 columnas — Pedidos no
 * versiona, a diferencia de Cotizaciones).
 */
final class PedidoIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<PedidoRow>                            $pedidoRows
     * @param  callable(int, int): list<PedidoItemRow>    $itemPageFetcher
     * @return array{
     *   pedidos: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   items_staged: int,
     *   changed_ids: list<int>,
     * }
     */
    public function load(array $pedidoRows, callable $itemPageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($pedidoRows, $itemPageFetcher, $pageSize): array {
            $itemsXor = $this->accumulateItemsXor($itemPageFetcher, $pageSize);

            [$counts, $changedIds, $changedKeys] = $this->upsertPedidos($pedidoRows, $itemsXor, now());

            $itemsStaged = 0;
            if ($changedIds !== []) {
                $this->deleteExistingItems($changedIds);
                $itemsStaged = $this->reinsertChangedItems($itemPageFetcher, $pageSize, $changedKeys);
            }

            return [
                'pedidos'      => $counts,
                'items_staged' => $itemsStaged,
                'changed_ids'  => $changedIds,
            ];
        });
    }

    /**
     * @param  callable(int, int): list<PedidoItemRow> $itemPageFetcher
     * @return array<string, int>
     */
    private function accumulateItemsXor(callable $itemPageFetcher, int $pageSize): array
    {
        $xor    = [];
        $offset = 0;

        while (true) {
            $page = $itemPageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            foreach ($page as $item) {
                $key = ExtractedPedidos::compositeKeyString($item->icgNumserie, $item->icgNumpedido, $item->icgN);
                $xor[$key] = ($xor[$key] ?? 0) ^ self::itemContentHashAsInt($item);
            }

            if (count($page) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return $xor;
    }

    private static function itemContentHashAsInt(PedidoItemRow $item): int
    {
        $hash = RowHasher::hash([
            'icg_codarticulo' => $item->icgCodarticulo,
            'referencia'      => $item->referencia,
            'descripcion'     => $item->descripcion,
            'cantidad'        => $item->cantidad,
            'precio'          => $item->precio,
            'total'           => $item->total,
        ]);

        return (int) hexdec(substr($hash, 0, 15));
    }

    /**
     * @param  list<PedidoRow>    $rows
     * @param  array<string, int> $itemsXor
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<int>, 2: list<string>}
     */
    private function upsertPedidos(array $rows, array $itemsXor, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], [], []];
        }

        $table       = (new Pedido())->getTable();
        $nuevos      = 0;
        $cambiados   = 0;
        $changedIds  = [];
        $changedKeys = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (PedidoRow $row) use ($itemsXor, $now): array {
                $key = ExtractedPedidos::compositeKeyString($row->icgNumserie, $row->icgNumpedido, $row->icgN);
                $hash = RowHasher::hash([
                    'icg_codcliente'    => $row->icgCodcliente,
                    'fechapedido'       => $row->fechapedido?->format('Y-m-d H:i:s'),
                    'fechaentrega'      => $row->fechaentrega?->format('Y-m-d H:i:s'),
                    'totneto'           => $row->totneto,
                    'icg_codvendedor'   => $row->icgCodvendedor,
                    'idestado'          => $row->idestado,
                    'icg_seriealbaran'  => $row->icgSeriealbaran,
                    'icg_numeroalbaran' => $row->icgNumeroalbaran,
                    'comision'          => $row->comision,
                    'items_xor'         => $itemsXor[$key] ?? 0,
                ]);

                return [
                    $row->icgNumserie, $row->icgNumpedido, $row->icgN, $row->icgCodcliente,
                    $row->fechapedido?->format('Y-m-d H:i:s'), $row->fechaentrega?->format('Y-m-d H:i:s'),
                    $row->totneto, $row->icgCodvendedor, $row->idestado, $row->icgSeriealbaran,
                    $row->icgNumeroalbaran, $row->comision, $hash, $now,
                ];
            }, $chunk);

            $sql = <<<SQL
                INSERT INTO {$table}
                    (icg_numserie, icg_numpedido, icg_n, icg_codcliente, fechapedido, fechaentrega,
                     totneto, icg_codvendedor, idestado, icg_seriealbaran, icg_numeroalbaran,
                     comision, source_hash, created_at)
                VALUES {$this->placeholdersFor(count($chunk), 14)}
                ON CONFLICT (icg_numserie, icg_numpedido, icg_n) DO UPDATE SET
                    icg_codcliente = EXCLUDED.icg_codcliente,
                    fechapedido = EXCLUDED.fechapedido,
                    fechaentrega = EXCLUDED.fechaentrega,
                    totneto = EXCLUDED.totneto,
                    icg_codvendedor = EXCLUDED.icg_codvendedor,
                    idestado = EXCLUDED.idestado,
                    icg_seriealbaran = EXCLUDED.icg_seriealbaran,
                    icg_numeroalbaran = EXCLUDED.icg_numeroalbaran,
                    comision = EXCLUDED.comision,
                    source_hash = EXCLUDED.source_hash
                WHERE {$table}.source_hash IS DISTINCT FROM EXCLUDED.source_hash
                RETURNING id, icg_numserie, icg_numpedido, icg_n, (xmax = 0) AS inserted
                SQL;

            $bindings = array_merge(...$values);

            /** @var list<object{id: int, icg_numserie: string, icg_numpedido: int, icg_n: int, inserted: bool}> $returned */
            $returned = DB::select($sql, $bindings);

            foreach ($returned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
                $changedIds[] = (int) $r->id;
                $changedKeys[] = ExtractedPedidos::compositeKeyString((string) $r->icg_numserie, (int) $r->icg_numpedido, (int) $r->icg_n);
            }
        }

        $total = count($rows);

        return [
            ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados],
            $changedIds,
            $changedKeys,
        ];
    }

    private function placeholdersFor(int $rows, int $columns): string
    {
        $one = '('.implode(', ', array_fill(0, $columns, '?')).')';

        return implode(', ', array_fill(0, $rows, $one));
    }

    /** @param list<int> $pedidoIds */
    private function deleteExistingItems(array $pedidoIds): void
    {
        foreach (array_chunk($pedidoIds, self::CHUNK_SIZE) as $chunk) {
            PedidoItem::query()->whereIn('legacy_pedido_id', $chunk)->delete();
        }
    }

    /**
     * @param  callable(int, int): list<PedidoItemRow> $itemPageFetcher
     * @param  list<string> $changedKeys
     */
    private function reinsertChangedItems(callable $itemPageFetcher, int $pageSize, array $changedKeys): int
    {
        $changedKeySet = array_flip($changedKeys);
        $idsByKey      = $this->resolvePedidoIds($changedKeys);

        $inserted = 0;
        $offset   = 0;

        while (true) {
            $page = $itemPageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            $values = [];
            foreach ($page as $item) {
                $key = ExtractedPedidos::compositeKeyString($item->icgNumserie, $item->icgNumpedido, $item->icgN);
                if (! isset($changedKeySet[$key])) {
                    continue;
                }

                $legacyPedidoId = $idsByKey[$key] ?? null;
                if ($legacyPedidoId === null) {
                    continue;
                }

                $values[] = [
                    'legacy_pedido_id' => $legacyPedidoId,
                    'icg_codarticulo'  => $item->icgCodarticulo,
                    'referencia'       => $item->referencia,
                    'descripcion'      => $item->descripcion,
                    'cantidad'         => $item->cantidad,
                    'precio'           => $item->precio,
                    'total'            => $item->total,
                ];
            }

            foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
                if ($chunk === []) {
                    continue;
                }

                PedidoItem::query()->insert($chunk);
                $inserted += count($chunk);
            }

            if (count($page) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return $inserted;
    }

    /**
     * @param  list<string> $keys
     * @return array<string, int>
     */
    private function resolvePedidoIds(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $tuples = array_map(static function (string $key): array {
            [$numserie, $numpedido, $n] = explode('|', $key);

            return [$numserie, (int) $numpedido, (int) $n];
        }, $keys);

        $map = [];
        foreach (array_chunk($tuples, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $bindings     = array_merge(...$chunk);

            $rows = Pedido::query()
                ->select(['id', 'icg_numserie', 'icg_numpedido', 'icg_n'])
                ->whereRaw("(icg_numserie, icg_numpedido, icg_n) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $key = ExtractedPedidos::compositeKeyString((string) $row->icg_numserie, (int) $row->icg_numpedido, (int) $row->icg_n);
                $map[$key] = (int) $row->id;
            }
        }

        return $map;
    }
}
