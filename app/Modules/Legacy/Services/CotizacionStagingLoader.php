<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;
use App\Modules\Legacy\Data\ExtractedCotizaciones;
use App\Modules\Legacy\Models\Cotizacion;
use App\Modules\Legacy\Models\CotizacionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-757 — "Loader-A" (staging). Upsert idempotente en `legacy.cotizaciones` por la llave
 * natural compuesta de ICG (`icg_numserie`+`icg_numpresupuesto`+`icg_n`+`icg_version`, UNIQUE).
 * Re-correr el mismo extracto dos veces nunca duplica ni pisa mal:
 *
 *  - `legacy.cotizaciones`: upsert por la llave compuesta. `legacy_cliente_id`/`orphan` NUNCA se
 *    incluyen en el `$update` de `upsert()` — así una segunda corrida jamás resetea el link/estado
 *    que puso `CotizacionMatcher` en una corrida anterior (mismo criterio que `sub_client_id`/
 *    `match_status` en `ClienteStagingLoader`).
 *  - `legacy.cotizacion_items`: PRESUPUESTOSLIN no declara una llave natural propia (ver docblock
 *    de la migración) — en vez de upsert por columna única, este Loader usa **delete-then-insert
 *    scoped a las cabeceras de ESTA corrida** (nunca a todo el historial de items): borra todas
 *    las líneas de las cabeceras presentes en el extracto actual, luego inserta el set fresco.
 *    Correr el mismo extracto dos veces produce exactamente el mismo conjunto final de filas —
 *    idempotente por resultado, no por upsert de columna única.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de cada modelo) — el llamador
 * (`App\Modules\Legacy\Console\Commands\LegacySyncCotizacionesCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`/`loadPaginated()`.
 *
 * `loadPaginated()` agregado 2026-08-21 (hallazgo real, ver docblock de `CotizacionExtractor`) —
 * `load()` sigue existiendo tal cual para datasets acotados (todos los tests existentes lo usan
 * sin cambios), pero asume que el llamador YA tiene las 715,530 líneas completas en memoria, que es
 * exactamente lo que causaba el OOM real en `dev.atlanticerp.ai`. `loadPaginated()` recibe un callback
 * que pagina la extracción de items y nunca materializa más de UNA página a la vez — misma
 * atomicidad (una sola `DB::transaction()`), mismo criterio delete-then-insert.
 */
final class CotizacionStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement (§13.3 del
     * diseño). Cotizaciones es, con diferencia, la entidad de mayor volumen real conocido hasta
     * ahora (94,823 cabeceras / 715,530 líneas, §2) — 500 deja el mismo margen de sobra que en
     * `ClienteStagingLoader`/`ProductoStagingLoader` para cualquiera de las tablas de esta
     * entidad (hasta 11 columnas en cabeceras, 4 tuplas por fila en el `whereRaw` de resolución
     * de ids → 500*4=2000 params, muy por debajo del límite).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `ClienteStagingLoader::load()`
     * (hallazgo Pre-QA SCRUM-750/755): un fallo a mitad de camino deja la conexión Postgres limpia
     * para que `legacy.sync_runs` pueda registrar la corrida fallida.
     *
     * @return array{cotizaciones: int, items: int, cotizacion_ids: list<int>}
     */
    public function load(ExtractedCotizaciones $extracted): array
    {
        return DB::transaction(function () use ($extracted): array {
            $idsByKey = $this->stageCotizaciones($extracted->cotizaciones);
            $this->deleteExistingItems(array_values($idsByKey));
            $itemsCount = $this->insertItemsPage($extracted->items, $idsByKey);

            return [
                'cotizaciones'   => count($extracted->cotizaciones),
                'items'          => $itemsCount,
                'cotizacion_ids' => array_values($idsByKey),
            ];
        });
    }

    /**
     * Entrada paginada para volumen real (ver docblock de la clase) — nunca materializa más de UNA
     * página de items en memoria a la vez. Misma atomicidad que `load()` (una sola transacción para
     * toda la corrida, cabeceras + todas las páginas de items).
     *
     * @param  list<CotizacionRow>                            $cotizacionRows
     * @param  callable(int, int): list<CotizacionItemRow>    $itemPageFetcher ($offset, $limit) => página, [] = fin
     * @return array{cotizaciones: int, items: int, cotizacion_ids: list<int>}
     */
    public function loadPaginated(array $cotizacionRows, callable $itemPageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($cotizacionRows, $itemPageFetcher, $pageSize): array {
            $idsByKey = $this->stageCotizaciones($cotizacionRows);
            $this->deleteExistingItems(array_values($idsByKey));

            $itemsCount = 0;
            $offset     = 0;
            while (true) {
                /** @var list<CotizacionItemRow> $page */
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
                'cotizaciones'   => count($cotizacionRows),
                'items'          => $itemsCount,
                'cotizacion_ids' => array_values($idsByKey),
            ];
        });
    }

    /**
     * Upsert de cabeceras + resolución de sus ids internos — compartido por `load()`/
     * `loadPaginated()`.
     *
     * @param  list<CotizacionRow>  $rows
     * @return array<string, int>   llave compuesta (string) => id interno
     */
    private function stageCotizaciones(array $rows): array
    {
        $this->upsertCotizaciones($rows, now());

        return $this->resolveCotizacionIds(self::compositeKeysFrom($rows));
    }

    /**
     * Mismo cálculo que `ExtractedCotizaciones::compositeKeys()`, pero operando directo sobre un
     * `list<CotizacionRow>` — `loadPaginated()` no siempre tiene un `ExtractedCotizaciones`
     * completo a mano (los items pueden llegar en páginas separadas).
     *
     * @param  list<CotizacionRow> $rows
     * @return list<array{0: string, 1: int, 2: int, 3: int}>
     */
    private static function compositeKeysFrom(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = ExtractedCotizaciones::compositeKeyString(
                $row->icgNumserie, $row->icgNumpresupuesto, $row->icgN, $row->icgVersion,
            );
            $seen[$key] = [$row->icgNumserie, $row->icgNumpresupuesto, $row->icgN, $row->icgVersion];
        }

        return array_values($seen);
    }

    /** @param list<CotizacionRow> $rows */
    private function upsertCotizaciones(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (CotizacionRow $row): array => [
            'icg_numserie'       => $row->icgNumserie,
            'icg_numpresupuesto' => $row->icgNumpresupuesto,
            'icg_n'              => $row->icgN,
            'icg_version'        => $row->icgVersion,
            'icg_codcliente'     => $row->icgCodcliente,
            'fecha'              => $row->fecha?->format('Y-m-d H:i:s'),
            'total_neto'         => $row->totalNeto,
            'total_bruto'        => $row->totalBruto,
            'icg_codvendedor'    => $row->icgCodvendedor,
            'idestado'           => $row->idestado,
            'supresupuesto'      => $row->supresupuesto,
            // SCRUM-842 — a diferencia de legacy_cliente_id/orphan (solo el Matcher los toca),
            // proyecto/promocion vienen del Extractor en cada corrida — sí van en el $update para
            // que una segunda corrida los refresque si cambiaron en ICG.
            'proyecto'           => $row->proyecto,
            'promocion'          => $row->promocion,
            'created_at'         => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Cotizacion::query()->upsert(
                $chunk,
                ['icg_numserie', 'icg_numpresupuesto', 'icg_n', 'icg_version'],
                [
                    'icg_codcliente', 'fecha', 'total_neto', 'total_bruto', 'icg_codvendedor',
                    'idestado', 'supresupuesto', 'proyecto', 'promocion',
                ],
            );
        }

        return count($values);
    }

    /**
     * Resuelve el `id` interno de cada cabecera por su llave natural compuesta — Postgres soporta
     * comparación de tuplas nativa (`WHERE (a,b,c,d) IN ((..),(..))`), evita tener que traer TODA
     * la tabla o hacer N queries individuales.
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: int}> $keys
     * @return array<string, int> llave compuesta (string) => id interno
     */
    private function resolveCotizacionIds(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $bindings     = [];
            foreach ($chunk as $key) {
                $bindings[] = $key[0];
                $bindings[] = $key[1];
                $bindings[] = $key[2];
                $bindings[] = $key[3];
            }

            $rows = Cotizacion::query()
                ->select(['id', 'icg_numserie', 'icg_numpresupuesto', 'icg_n', 'icg_version'])
                ->whereRaw("(icg_numserie, icg_numpresupuesto, icg_n, icg_version) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $compositeKey = ExtractedCotizaciones::compositeKeyString(
                    (string) $row->icg_numserie,
                    (int) $row->icg_numpresupuesto,
                    (int) $row->icg_n,
                    (int) $row->icg_version,
                );

                $map[$compositeKey] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * Borra todas las líneas de las cabeceras de ESTA corrida (nunca todo el historial) — ver
     * docblock de la clase para el porqué de este patrón en vez de upsert por columna única.
     * Se corre UNA sola vez por corrida (antes de la primera página), nunca por página — si se
     * corriera por página, cada página borraría lo que insertó la anterior.
     *
     * @param list<int> $cotizacionIds
     */
    private function deleteExistingItems(array $cotizacionIds): void
    {
        foreach (array_chunk($cotizacionIds, self::CHUNK_SIZE) as $chunk) {
            CotizacionItem::query()->whereIn('legacy_cotizacion_id', $chunk)->delete();
        }
    }

    /**
     * Inserta UNA página/lote de items — llamable repetidas veces por `loadPaginated()` sin volver
     * a borrar nada (eso ya lo hizo `deleteExistingItems()` una sola vez antes del loop).
     *
     * @param list<CotizacionItemRow> $rows
     * @param array<string, int>      $idsByKey llave compuesta de la cabecera (string) => id interno
     */
    private function insertItemsPage(array $rows, array $idsByKey): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = [];
        foreach ($rows as $row) {
            $compositeKey = ExtractedCotizaciones::compositeKeyString(
                $row->icgNumserie,
                $row->icgNumpresupuesto,
                $row->icgN,
                $row->icgVersion,
            );

            $legacyCotizacionId = $idsByKey[$compositeKey] ?? null;
            if ($legacyCotizacionId === null) {
                // Huérfano dentro de esta corrida (la cabecera correspondiente no está en el
                // extracto actual) — se descarta, nunca rompe el resto (§13.2 del diseño, tolerar
                // huérfanos siempre).
                continue;
            }

            $values[] = [
                'legacy_cotizacion_id' => $legacyCotizacionId,
                'icg_codarticulo'      => $row->icgCodarticulo,
                'referencia'           => $row->referencia,
                'descripcion'          => $row->descripcion,
                'cantidad'             => $row->cantidad,
                'precio'               => $row->precio,
                'descuento'            => $row->descuento,
                'total'                => $row->total,
                'costo'                => $row->costo,
            ];
        }

        if ($values === []) {
            return 0;
        }

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            CotizacionItem::query()->insert($chunk);
        }

        return count($values);
    }
}
