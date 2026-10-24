<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\AlbaranCompraItemRow;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Data\ExtractedAlbaranesCompra;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — equivalente de compras a `AlbaranIncrementalStagingLoader` (ver su docblock para el
 * mecanismo completo: fold XOR de líneas por página, plegado en el `source_hash` de la cabecera —
 * corregido 2026-09-14, la primera versión de ambas clases hasheaba solo la cabecera).
 */
final class AlbaranCompraIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<AlbaranCompraRow>                            $albaranRows
     * @param  callable(int, int): list<AlbaranCompraItemRow>    $itemPageFetcher
     * @return array{albaranes: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, items: int, changed_albaran_ids: list<int>}
     */
    public function loadPaginated(array $albaranRows, callable $itemPageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($albaranRows, $itemPageFetcher, $pageSize): array {
            $itemsXor = $this->accumulateItemsXor($itemPageFetcher, $pageSize);

            [$albaranesCounts, $changedKeys] = $this->upsertAlbaranes($albaranRows, $itemsXor, now());

            $changedIds = $this->resolveAlbaranIds($changedKeys);

            $itemsCount = 0;
            if ($changedIds !== []) {
                $this->deleteExistingItems(array_values($changedIds));
                $itemsCount = $this->reinsertChangedItems($itemPageFetcher, $pageSize, $changedKeys);
            }

            return [
                'albaranes'           => $albaranesCounts,
                'items'               => $itemsCount,
                'changed_albaran_ids' => array_values($changedIds),
            ];
        });
    }

    /**
     * @param  callable(int, int): list<AlbaranCompraItemRow> $itemPageFetcher
     * @return array<string, int>
     */
    private function accumulateItemsXor(callable $itemPageFetcher, int $pageSize): array
    {
        $xor    = [];
        $offset = 0;

        while (true) {
            /** @var list<AlbaranCompraItemRow> $page */
            $page = $itemPageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            foreach ($page as $item) {
                $key = ExtractedAlbaranesCompra::compositeKeyString($item->icgNumserie, $item->icgNumalbaran, $item->icgN);
                $xor[$key] = ($xor[$key] ?? 0) ^ self::itemContentHashAsInt($item);
            }

            if (count($page) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return $xor;
    }

    private static function itemContentHashAsInt(AlbaranCompraItemRow $item): int
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
     * @param  list<AlbaranCompraRow> $rows
     * @param  array<string, int>     $itemsXor
     * @return array{0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int}, 1: list<array{0: string, 1: int, 2: string}>}
     */
    private function upsertAlbaranes(array $rows, array $itemsXor, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], []];
        }

        $table       = (new AlbaranCompra())->getTable();
        $nuevos      = 0;
        $cambiados   = 0;
        $changedKeys = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (AlbaranCompraRow $row) use ($itemsXor, $now): array {
                $key  = ExtractedAlbaranesCompra::compositeKeyString($row->icgNumserie, $row->icgNumalbaran, $row->icgN);
                $hash = RowHasher::hash([
                    'icg_codproveedor' => $row->icgCodproveedor,
                    'fecha'            => $row->fecha?->format('Y-m-d H:i:s'),
                    'totalneto'        => $row->totalneto,
                    'facturado'        => $row->facturado,
                    'icg_numseriefac'  => $row->icgNumseriefac,
                    'icg_numfac'       => $row->icgNumfac,
                    'items_xor'        => $itemsXor[$key] ?? 0,
                ]);

                return [
                    $row->icgNumserie, $row->icgNumalbaran, $row->icgN, $row->icgCodproveedor,
                    $row->fecha?->format('Y-m-d H:i:s'), $row->totalneto, $row->facturado,
                    $row->icgNumseriefac, $row->icgNumfac, $hash, $now,
                ];
            }, $chunk);

            $rowsReturned = $this->upsertChunk(
                table: $table,
                columns: ['icg_numserie', 'icg_numalbaran', 'icg_n', 'icg_codproveedor', 'fecha', 'totalneto', 'facturado', 'icg_numseriefac', 'icg_numfac', 'source_hash', 'created_at'],
                values: $values,
                conflictColumns: ['icg_numserie', 'icg_numalbaran', 'icg_n'],
                updateColumns: ['icg_codproveedor', 'fecha', 'totalneto', 'facturado', 'icg_numseriefac', 'icg_numfac', 'source_hash'],
            );

            foreach ($rowsReturned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
                $changedKeys[] = [(string) $r->k1, (int) $r->k2, (string) $r->k3];
            }
        }

        $total = count($rows);

        return [
            ['total' => $total, 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'sin_cambios' => $total - $nuevos - $cambiados],
            $changedKeys,
        ];
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}> $keys
     * @return array<string, int>
     */
    private function resolveAlbaranIds(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $bindings     = [];
            foreach ($chunk as $key) {
                $bindings[] = $key[0];
                $bindings[] = $key[1];
                $bindings[] = $key[2];
            }

            $rows = AlbaranCompra::query()
                ->select(['id', 'icg_numserie', 'icg_numalbaran', 'icg_n'])
                ->whereRaw("(icg_numserie, icg_numalbaran, icg_n) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $compositeKey = ExtractedAlbaranesCompra::compositeKeyString(
                    (string) $row->icg_numserie,
                    (int) $row->icg_numalbaran,
                    (string) $row->icg_n,
                );

                $map[$compositeKey] = (int) $row->id;
            }
        }

        return $map;
    }

    /** @param list<int> $albaranIds */
    private function deleteExistingItems(array $albaranIds): void
    {
        foreach (array_chunk($albaranIds, self::CHUNK_SIZE) as $chunk) {
            AlbaranCompraItem::query()->whereIn('legacy_albaran_compra_id', $chunk)->delete();
        }
    }

    /**
     * @param  callable(int, int): list<AlbaranCompraItemRow> $itemPageFetcher
     * @param  list<array{0: string, 1: int, 2: string}> $changedKeys
     */
    private function reinsertChangedItems(callable $itemPageFetcher, int $pageSize, array $changedKeys): int
    {
        $changedKeySet = [];
        foreach ($changedKeys as $key) {
            $changedKeySet[ExtractedAlbaranesCompra::compositeKeyString($key[0], $key[1], $key[2])] = true;
        }
        $idsByKey = $this->resolveAlbaranIds($changedKeys);

        $inserted = 0;
        $offset   = 0;

        while (true) {
            /** @var list<AlbaranCompraItemRow> $page */
            $page = $itemPageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            $values = [];
            foreach ($page as $item) {
                $key = ExtractedAlbaranesCompra::compositeKeyString($item->icgNumserie, $item->icgNumalbaran, $item->icgN);
                if (! isset($changedKeySet[$key])) {
                    continue;
                }

                $legacyAlbaranId = $idsByKey[$key] ?? null;
                if ($legacyAlbaranId === null) {
                    continue;
                }

                $values[] = [
                    'legacy_albaran_compra_id' => $legacyAlbaranId,
                    'icg_codarticulo'          => $item->icgCodarticulo,
                    'referencia'               => $item->referencia,
                    'descripcion'              => $item->descripcion,
                    'cantidad'                 => $item->cantidad,
                    'precio'                   => $item->precio,
                    'total'                    => $item->total,
                ];
            }

            foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
                if ($chunk === []) {
                    continue;
                }

                AlbaranCompraItem::query()->insert($chunk);
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
     * @param  list<string> $columns
     * @param  list<list<mixed>> $values
     * @param  list<string> $conflictColumns
     * @param  list<string> $updateColumns
     * @return list<object{k1: string, k2: int, k3: string, inserted: bool}>
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
            RETURNING icg_numserie AS k1, icg_numalbaran AS k2, icg_n AS k3, (xmax = 0) AS inserted
            SQL;

        $bindings = array_merge(...$values);

        /** @var list<object{k1: string, k2: int, k3: string, inserted: bool}> $result */
        $result = DB::select($sql, $bindings);

        return $result;
    }
}
