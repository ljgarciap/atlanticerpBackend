<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Data\ExtractedCotizaciones;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use App\Modules\Legacy\Support\RowHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-838 — variante incremental de `CotizacionStagingLoader` (que NO se modifica, sigue siendo
 * el camino full-sync). PRESUPUESTOSLIN no tiene llave propia (delete-then-insert, ver docblock de
 * `CotizacionStagingLoader`) — no se puede hacer un ON CONFLICT por línea como en Productos. En
 * vez de eso, el hash de CADA línea se pliega (XOR) en el `source_hash` de su cabecera: un cambio
 * en cualquier línea cambia el hash de la cabecera, sin necesitar una columna de hash por línea.
 * XOR es orden-independiente a propósito — PRESUPUESTOSLIN no garantiza el mismo orden entre
 * corridas para líneas de la misma cabecera (sin columna de secuencia propia, ver MER), así que un
 * hash sensible al orden de llegada daría falsos "cambió" solo por reordenamiento del lado de ICG.
 *
 * DOS PASADAS sobre `itemPageFetcher` (costo aceptado, documentado): la primera acumula el XOR de
 * items por cabecera sin necesitar tener más de una página en memoria a la vez (mismo límite de
 * memoria que `CotizacionStagingLoader::loadPaginated()`, mismo motivo — OOM real ya documentado,
 * ver docblock de `CotizacionExtractor`). Recién con el XOR completo se sabe qué cabeceras
 * cambiaron de verdad. La segunda pasada vuelve a pedir las páginas de items (mismo volumen de red
 * contra ICG que ya se paga en cada corrida, full-sync o incremental — la extracción sigue sin ser
 * incremental de verdad, ver RowHasher/ticket) pero SOLO inserta las líneas de cabeceras que
 * cambiaron — se descartan en memoria las del resto, nunca se bufferean todas.
 */
final class CotizacionIncrementalStagingLoader
{
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<CotizacionRow>                            $cotizacionRows
     * @param  callable(int, int): list<CotizacionItemRow>    $itemPageFetcher ($offset, $limit) => página, [] = fin
     * @return array{
     *   cotizaciones: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   items_staged: int,
     *   changed_ids: list<int>,
     * }
     */
    public function load(array $cotizacionRows, callable $itemPageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($cotizacionRows, $itemPageFetcher, $pageSize): array {
            $itemsXor = $this->accumulateItemsXor($itemPageFetcher, $pageSize);

            [$counts, $changedIds, $changedKeys] = $this->upsertCotizaciones($cotizacionRows, $itemsXor, now());

            $itemsStaged = 0;
            if ($changedIds !== []) {
                $this->deleteExistingItems($changedIds);
                $itemsStaged = $this->reinsertChangedItems($itemPageFetcher, $pageSize, $changedKeys);
            }

            return [
                'cotizaciones' => $counts,
                'items_staged' => $itemsStaged,
                'changed_ids'  => $changedIds,
            ];
        });
    }

    /**
     * Pasada 1 — nunca más de una página de items en memoria, solo el acumulador (chico: una
     * entrada int por cabecera vista, no por línea).
     *
     * @param  callable(int, int): list<CotizacionItemRow> $itemPageFetcher
     * @return array<string, int> llave compuesta de cabecera => XOR acumulado de sus líneas
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
                $key = ExtractedCotizaciones::compositeKeyString($item->icgNumserie, $item->icgNumpresupuesto, $item->icgN, $item->icgVersion);
                $xor[$key] = ($xor[$key] ?? 0) ^ self::itemContentHashAsInt($item);
            }

            if (count($page) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return $xor;
    }

    /**
     * Solo campos de CONTENIDO de la línea — la llave de cabecera nunca entra acá (es la misma
     * para todas las líneas de esa cabecera, no aporta señal de cambio).
     */
    private static function itemContentHashAsInt(CotizacionItemRow $item): int
    {
        $hash = RowHasher::hash([
            'icg_codarticulo' => $item->icgCodarticulo,
            'referencia'      => $item->referencia,
            'descripcion'     => $item->descripcion,
            'cantidad'        => $item->cantidad,
            'precio'          => $item->precio,
            'descuento'       => $item->descuento,
            'total'           => $item->total,
            'costo'           => $item->costo,
        ]);

        // 15 hex chars (~60 bits) — entra holgado en un int de 64 bits de PHP. No es criptografía,
        // es un acumulador de detección de cambio; suficiente para no colisionar en la práctica.
        return (int) hexdec(substr($hash, 0, 15));
    }

    /**
     * @param  list<CotizacionRow>    $rows
     * @param  array<string, int>     $itemsXor
     * @return array{
     *   0: array{total: int, nuevos: int, cambiados: int, sin_cambios: int},
     *   1: list<int>,
     *   2: list<string>,
     * }
     */
    private function upsertCotizaciones(array $rows, array $itemsXor, Carbon $now): array
    {
        if ($rows === []) {
            return [['total' => 0, 'nuevos' => 0, 'cambiados' => 0, 'sin_cambios' => 0], [], []];
        }

        $table      = (new Cotizacion())->getTable();
        $nuevos     = 0;
        $cambiados  = 0;
        $changedIds = [];
        $changedKeys = [];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            $values = array_map(function (CotizacionRow $row) use ($itemsXor, $now): array {
                $key = ExtractedCotizaciones::compositeKeyString($row->icgNumserie, $row->icgNumpresupuesto, $row->icgN, $row->icgVersion);
                $hash = RowHasher::hash([
                    'icg_codcliente'  => $row->icgCodcliente,
                    'fecha'           => $row->fecha?->format('Y-m-d H:i:s'),
                    'total_neto'      => $row->totalNeto,
                    'total_bruto'     => $row->totalBruto,
                    'icg_codvendedor' => $row->icgCodvendedor,
                    'idestado'        => $row->idestado,
                    'supresupuesto'   => $row->supresupuesto,
                    'proyecto'        => $row->proyecto,
                    'promocion'       => $row->promocion,
                    'items_xor'       => $itemsXor[$key] ?? 0,
                ]);

                return [
                    $row->icgNumserie, $row->icgNumpresupuesto, $row->icgN, $row->icgVersion,
                    $row->icgCodcliente, $row->fecha?->format('Y-m-d H:i:s'), $row->totalNeto,
                    $row->totalBruto, $row->icgCodvendedor, $row->idestado, $row->supresupuesto,
                    $row->proyecto, $row->promocion, $hash, $now,
                ];
            }, $chunk);

            $sql = <<<SQL
                INSERT INTO {$table}
                    (icg_numserie, icg_numpresupuesto, icg_n, icg_version, icg_codcliente, fecha,
                     total_neto, total_bruto, icg_codvendedor, idestado, supresupuesto, proyecto,
                     promocion, source_hash, created_at)
                VALUES {$this->placeholdersFor(count($chunk), 15)}
                ON CONFLICT (icg_numserie, icg_numpresupuesto, icg_n, icg_version) DO UPDATE SET
                    icg_codcliente = EXCLUDED.icg_codcliente,
                    fecha = EXCLUDED.fecha,
                    total_neto = EXCLUDED.total_neto,
                    total_bruto = EXCLUDED.total_bruto,
                    icg_codvendedor = EXCLUDED.icg_codvendedor,
                    idestado = EXCLUDED.idestado,
                    supresupuesto = EXCLUDED.supresupuesto,
                    proyecto = EXCLUDED.proyecto,
                    promocion = EXCLUDED.promocion,
                    source_hash = EXCLUDED.source_hash
                WHERE {$table}.source_hash IS DISTINCT FROM EXCLUDED.source_hash
                RETURNING id, icg_numserie, icg_numpresupuesto, icg_n, icg_version, (xmax = 0) AS inserted
                SQL;

            $bindings = array_merge(...$values);

            /** @var list<object{id: int, icg_numserie: string, icg_numpresupuesto: int, icg_n: int, icg_version: int, inserted: bool}> $returned */
            $returned = DB::select($sql, $bindings);

            foreach ($returned as $r) {
                $r->inserted ? $nuevos++ : $cambiados++;
                $changedIds[] = (int) $r->id;
                $changedKeys[] = ExtractedCotizaciones::compositeKeyString(
                    (string) $r->icg_numserie, (int) $r->icg_numpresupuesto, (int) $r->icg_n, (int) $r->icg_version,
                );
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

    /** @param list<int> $cotizacionIds */
    private function deleteExistingItems(array $cotizacionIds): void
    {
        foreach (array_chunk($cotizacionIds, self::CHUNK_SIZE) as $chunk) {
            CotizacionItem::query()->whereIn('legacy_cotizacion_id', $chunk)->delete();
        }
    }

    /**
     * Pasada 2 — vuelve a pedir las páginas de items (costo de red aceptado, ver docblock de la
     * clase) pero solo inserta las de cabeceras en `$changedKeys`; el resto se descarta apenas se
     * lee, nunca se bufferea.
     *
     * @param  callable(int, int): list<CotizacionItemRow> $itemPageFetcher
     * @param  list<string> $changedKeys
     */
    private function reinsertChangedItems(callable $itemPageFetcher, int $pageSize, array $changedKeys): int
    {
        $changedKeySet = array_flip($changedKeys);
        $idsByKey       = $this->resolveCotizacionIds($changedKeys);

        $inserted = 0;
        $offset   = 0;

        while (true) {
            $page = $itemPageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            $values = [];
            foreach ($page as $item) {
                $key = ExtractedCotizaciones::compositeKeyString($item->icgNumserie, $item->icgNumpresupuesto, $item->icgN, $item->icgVersion);
                if (! isset($changedKeySet[$key])) {
                    continue;
                }

                $legacyCotizacionId = $idsByKey[$key] ?? null;
                if ($legacyCotizacionId === null) {
                    continue;
                }

                $values[] = [
                    'legacy_cotizacion_id' => $legacyCotizacionId,
                    'icg_codarticulo'      => $item->icgCodarticulo,
                    'referencia'           => $item->referencia,
                    'descripcion'          => $item->descripcion,
                    'cantidad'             => $item->cantidad,
                    'precio'               => $item->precio,
                    'descuento'            => $item->descuento,
                    'total'                => $item->total,
                    'costo'                => $item->costo,
                ];
            }

            foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
                if ($chunk === []) {
                    continue;
                }

                CotizacionItem::query()->insert($chunk);
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
     * @param  list<string> $keys llaves compuestas (string) ya armadas por compositeKeyString()
     * @return array<string, int> llave compuesta => id interno
     */
    private function resolveCotizacionIds(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $tuples = array_map(static function (string $key): array {
            [$numserie, $numpresupuesto, $n, $version] = explode('|', $key);

            return [$numserie, (int) $numpresupuesto, (int) $n, (int) $version];
        }, $keys);

        $map = [];
        foreach (array_chunk($tuples, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $bindings     = array_merge(...$chunk);

            $rows = Cotizacion::query()
                ->select(['id', 'icg_numserie', 'icg_numpresupuesto', 'icg_n', 'icg_version'])
                ->whereRaw("(icg_numserie, icg_numpresupuesto, icg_n, icg_version) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $key = ExtractedCotizaciones::compositeKeyString(
                    (string) $row->icg_numserie, (int) $row->icg_numpresupuesto, (int) $row->icg_n, (int) $row->icg_version,
                );
                $map[$key] = (int) $row->id;
            }
        }

        return $map;
    }
}
