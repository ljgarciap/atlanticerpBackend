<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\AlbaranCompraItemRow;
use App\Modules\Legacy\Data\AlbaranCompraRow;
use App\Modules\Legacy\Data\ExtractedAlbaranesCompra;
use App\Modules\Legacy\Models\AlbaranCompra;
use App\Modules\Legacy\Models\AlbaranCompraItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-840 — "Loader-A" (staging), mismo patrón exacto que `AlbaranStagingLoader`:
 *
 *  - `legacy.albaranes_compra`: upsert por la llave compuesta. `legacy_proveedor_id`/`orphan`
 *    nunca se incluyen en el `$update` — una segunda corrida nunca resetea lo que puso
 *    `AlbaranCompraMatcher`.
 *  - `legacy.albaran_compra_items`: delete-then-insert scoped a las cabeceras de ESTA corrida
 *    (ALBCOMPRALIN no declara llave natural propia).
 *
 * Requiere tenant activo — el llamador (`App\Modules\Legacy\Console\Commands\LegacySyncAlbaranesCompraCommand`)
 * es responsable de `makeCurrent()` antes de invocar `loadPaginated()`.
 */
final class AlbaranCompraStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<AlbaranCompraRow>                            $albaranRows
     * @param  callable(int, int): list<AlbaranCompraItemRow>    $itemPageFetcher ($offset, $limit) => página, [] = fin
     * @return array{albaranes: int, items: int, albaran_ids: list<int>}
     */
    public function loadPaginated(array $albaranRows, callable $itemPageFetcher, int $pageSize = 20000): array
    {
        $now = now();

        return DB::transaction(function () use ($albaranRows, $itemPageFetcher, $pageSize, $now): array {
            $albaranesCount = $this->upsertAlbaranes($albaranRows, $now);
            $idsByKey       = $this->resolveAlbaranIds(self::compositeKeysFrom($albaranRows));
            $this->deleteExistingItems(array_values($idsByKey));

            $itemsCount = 0;
            $offset     = 0;
            while (true) {
                /** @var list<AlbaranCompraItemRow> $page */
                $page = $itemPageFetcher($offset, $pageSize);
                if ($page === []) {
                    break;
                }

                $itemsCount += $this->insertItemsPage($page, $idsByKey);

                if (count($page) < $pageSize) {
                    break;
                }

                $offset += $pageSize;
            }

            return [
                'albaranes'   => $albaranesCount,
                'items'       => $itemsCount,
                'albaran_ids' => array_values($idsByKey),
            ];
        });
    }

    /**
     * @param  list<AlbaranCompraRow> $rows
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private static function compositeKeysFrom(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = ExtractedAlbaranesCompra::compositeKeyString($row->icgNumserie, $row->icgNumalbaran, $row->icgN);
            $seen[$key] = [$row->icgNumserie, $row->icgNumalbaran, $row->icgN];
        }

        return array_values($seen);
    }

    /** @param list<AlbaranCompraRow> $rows */
    private function upsertAlbaranes(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (AlbaranCompraRow $row): array => [
            'icg_numserie'    => $row->icgNumserie,
            'icg_numalbaran'  => $row->icgNumalbaran,
            'icg_n'           => $row->icgN,
            'icg_codproveedor' => $row->icgCodproveedor,
            'fecha'           => $row->fecha?->format('Y-m-d H:i:s'),
            'totalneto'       => $row->totalneto,
            'facturado'       => $row->facturado,
            'icg_numseriefac' => $row->icgNumseriefac,
            'icg_numfac'      => $row->icgNumfac,
            'created_at'      => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            AlbaranCompra::query()->upsert(
                $chunk,
                ['icg_numserie', 'icg_numalbaran', 'icg_n'],
                ['icg_codproveedor', 'fecha', 'totalneto', 'facturado', 'icg_numseriefac', 'icg_numfac'],
            );
        }

        return count($values);
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
     * @param list<AlbaranCompraItemRow> $rows
     * @param array<string, int>         $idsByKey
     */
    private function insertItemsPage(array $rows, array $idsByKey): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = [];
        foreach ($rows as $row) {
            $compositeKey = ExtractedAlbaranesCompra::compositeKeyString(
                $row->icgNumserie,
                $row->icgNumalbaran,
                $row->icgN,
            );

            $legacyAlbaranId = $idsByKey[$compositeKey] ?? null;
            if ($legacyAlbaranId === null) {
                continue;
            }

            $values[] = [
                'legacy_albaran_compra_id' => $legacyAlbaranId,
                'icg_codarticulo'          => $row->icgCodarticulo,
                'referencia'               => $row->referencia,
                'descripcion'              => $row->descripcion,
                'cantidad'                 => $row->cantidad,
                'precio'                   => $row->precio,
                'total'                    => $row->total,
            ];
        }

        if ($values === []) {
            return 0;
        }

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            AlbaranCompraItem::query()->insert($chunk);
        }

        return count($values);
    }
}
