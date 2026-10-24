<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\AlbaranItemRow;
use App\Modules\Legacy\Data\AlbaranRow;
use App\Modules\Legacy\Data\ExtractedAlbaranes;
use App\Modules\Legacy\Models\Albaran;
use App\Modules\Legacy\Models\AlbaranItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-759 — "Loader-A" (staging). Upsert idempotente en `legacy.albaranes` por la llave natural
 * compuesta de ICG (`icg_numserie`+`icg_numalbaran`+`icg_n`, UNIQUE — 3 columnas, mismo patrón que
 * `PedidoStagingLoader`; `icg_n` es texto, no entero, ver docblock de la migración). Re-correr el
 * mismo extracto dos veces nunca duplica ni pisa mal:
 *
 *  - `legacy.albaranes`: upsert por la llave compuesta. `legacy_cliente_id`/`orphan` NUNCA se
 *    incluyen en el `$update` de `upsert()` — así una segunda corrida jamás resetea el link/estado
 *    que puso `AlbaranMatcher` en una corrida anterior (mismo criterio que `PedidoStagingLoader`).
 *  - `legacy.albaran_items`: ALBVENTALIN no declara una llave natural propia (ver docblock de la
 *    migración) — en vez de upsert por columna única, este Loader usa **delete-then-insert scoped
 *    a las cabeceras de ESTA corrida** (nunca a todo el historial de items): borra todas las
 *    líneas de las cabeceras presentes en el extracto actual, luego inserta el set fresco. Correr
 *    el mismo extracto dos veces produce exactamente el mismo conjunto final de filas —
 *    idempotente por resultado, no por upsert de columna única.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de cada modelo) — el llamador
 * (`App\Console\Commands\LegacySyncAlbaranesCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`/`loadPaginated()`.
 *
 * `loadPaginated()` agregado 2026-08-27 (mismo hallazgo real que `CotizacionStagingLoader` el
 * 2026-08-21, ver docblock de `App\Modules\Legacy\Extractors\AlbaranExtractor`) — `load()` sigue
 * existiendo tal cual para datasets acotados (todos los tests existentes lo usan sin cambios), pero
 * asume que el llamador YA tiene las ~199,766 líneas completas en memoria, que es exactamente lo
 * que causa el OOM real en `dev.atlanticerp.ai`. `loadPaginated()` recibe un callback que pagina la
 * extracción de items y nunca materializa más de UNA página a la vez — misma atomicidad (una sola
 * `DB::transaction()`), mismo criterio delete-then-insert.
 */
final class AlbaranStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement (§13.3 del
     * diseño). Mismo margen que `PedidoStagingLoader`/`CotizacionStagingLoader`/
     * `ClienteStagingLoader` — 500 deja de sobra para cualquiera de las tablas de esta entidad
     * (hasta 3 tuplas por fila en el `whereRaw` de resolución de ids → 500*3=1500 params, muy por
     * debajo del límite).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `PedidoStagingLoader::load()`
     * (hallazgo Pre-QA SCRUM-750/755): un fallo a mitad de camino deja la conexión Postgres limpia
     * para que `legacy.sync_runs` pueda registrar la corrida fallida.
     *
     * @return array{albaranes: int, items: int, albaran_ids: list<int>}
     */
    public function load(ExtractedAlbaranes $extracted): array
    {
        $now = now();

        return DB::transaction(function () use ($extracted, $now): array {
            $albaranesCount = $this->upsertAlbaranes($extracted->albaranes, $now);
            $idsByKey        = $this->resolveAlbaranIds($extracted->compositeKeys());
            $this->deleteExistingItems(array_values($idsByKey));
            $itemsCount      = $this->insertItemsPage($extracted->items, $idsByKey);

            return [
                'albaranes'   => $albaranesCount,
                'items'       => $itemsCount,
                'albaran_ids' => array_values($idsByKey),
            ];
        });
    }

    /**
     * Entrada paginada para volumen real (ver docblock de la clase) — nunca materializa más de UNA
     * página de items en memoria a la vez. Misma atomicidad que `load()` (una sola transacción para
     * toda la corrida, cabeceras + todas las páginas de items).
     *
     * @param  list<AlbaranRow>                            $albaranRows
     * @param  callable(int, int): list<AlbaranItemRow>    $itemPageFetcher ($offset, $limit) => página, [] = fin
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
                /** @var list<AlbaranItemRow> $page */
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
     * Mismo cálculo que `ExtractedAlbaranes::compositeKeys()`, pero operando directo sobre un
     * `list<AlbaranRow>` — `loadPaginated()` no siempre tiene un `ExtractedAlbaranes` completo a
     * mano (los items llegan en páginas separadas).
     *
     * @param  list<AlbaranRow> $rows
     * @return list<array{0: string, 1: int, 2: string}>
     */
    private static function compositeKeysFrom(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = ExtractedAlbaranes::compositeKeyString($row->icgNumserie, $row->icgNumalbaran, $row->icgN);
            $seen[$key] = [$row->icgNumserie, $row->icgNumalbaran, $row->icgN];
        }

        return array_values($seen);
    }

    /** @param list<AlbaranRow> $rows */
    private function upsertAlbaranes(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (AlbaranRow $row): array => [
            'icg_numserie'     => $row->icgNumserie,
            'icg_numalbaran'   => $row->icgNumalbaran,
            'icg_n'            => $row->icgN,
            'icg_codcliente'   => $row->icgCodcliente,
            'fecha'            => $row->fecha?->format('Y-m-d H:i:s'),
            'totalneto'        => $row->totalneto,
            'facturado'        => $row->facturado,
            'icg_codvendedor'  => $row->icgCodvendedor,
            'icg_numseriefac'  => $row->icgNumseriefac,
            'icg_numfac'       => $row->icgNumfac,
            // SCRUM-842 — viene del Extractor en cada corrida (no del Matcher), sí va en el
            // $update para que una segunda corrida la refresque si cambió en ICG.
            'observaciones'    => $row->observaciones,
            'created_at'       => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Albaran::query()->upsert(
                $chunk,
                ['icg_numserie', 'icg_numalbaran', 'icg_n'],
                [
                    'icg_codcliente', 'fecha', 'totalneto', 'facturado', 'icg_codvendedor',
                    'icg_numseriefac', 'icg_numfac', 'observaciones',
                ],
            );
        }

        return count($values);
    }

    /**
     * Resuelve el `id` interno de cada cabecera por su llave natural compuesta — Postgres soporta
     * comparación de tuplas nativa (`WHERE (a,b,c) IN ((..),(..))`), evita tener que traer TODA la
     * tabla o hacer N queries individuales.
     *
     * @param  list<array{0: string, 1: int, 2: string}> $keys
     * @return array<string, int> llave compuesta (string) => id interno
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

            $rows = Albaran::query()
                ->select(['id', 'icg_numserie', 'icg_numalbaran', 'icg_n'])
                ->whereRaw("(icg_numserie, icg_numalbaran, icg_n) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $compositeKey = ExtractedAlbaranes::compositeKeyString(
                    (string) $row->icg_numserie,
                    (int) $row->icg_numalbaran,
                    (string) $row->icg_n,
                );

                $map[$compositeKey] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * Borra todas las líneas de las cabeceras de ESTA corrida (nunca todo el historial) — ver
     * docblock de la clase para el porqué de este patrón en vez de upsert por columna única. Se
     * corre UNA sola vez por corrida (antes de la primera página), nunca por página — si se
     * corriera por página, cada página borraría lo que insertó la anterior.
     *
     * @param list<int> $albaranIds
     */
    private function deleteExistingItems(array $albaranIds): void
    {
        foreach (array_chunk($albaranIds, self::CHUNK_SIZE) as $chunk) {
            AlbaranItem::query()->whereIn('legacy_albaran_id', $chunk)->delete();
        }
    }

    /**
     * Inserta UNA página/lote de items — llamable repetidas veces por `loadPaginated()` sin volver
     * a borrar nada (eso ya lo hizo `deleteExistingItems()` una sola vez antes del loop).
     *
     * @param list<AlbaranItemRow> $rows
     * @param array<string, int>   $idsByKey llave compuesta de la cabecera (string) => id interno
     */
    private function insertItemsPage(array $rows, array $idsByKey): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = [];
        foreach ($rows as $row) {
            $compositeKey = ExtractedAlbaranes::compositeKeyString(
                $row->icgNumserie,
                $row->icgNumalbaran,
                $row->icgN,
            );

            $legacyAlbaranId = $idsByKey[$compositeKey] ?? null;
            if ($legacyAlbaranId === null) {
                // Huérfano dentro de esta corrida (la cabecera correspondiente no está en el
                // extracto actual) — se descarta, nunca rompe el resto (§13.2 del diseño, tolerar
                // huérfanos siempre).
                continue;
            }

            $values[] = [
                'legacy_albaran_id' => $legacyAlbaranId,
                'icg_codarticulo'   => $row->icgCodarticulo,
                'referencia'        => $row->referencia,
                'descripcion'       => $row->descripcion,
                'cantidad'          => $row->cantidad,
                'precio'            => $row->precio,
                'total'             => $row->total,
            ];
        }

        if ($values === []) {
            return 0;
        }

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            AlbaranItem::query()->insert($chunk);
        }

        return count($values);
    }
}
