<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\ExtractedPedidos;
use App\Modules\Legacy\Data\PedidoItemRow;
use App\Modules\Legacy\Data\PedidoRow;
use App\Modules\Legacy\Models\Pedido;
use App\Modules\Legacy\Models\PedidoItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-758 — "Loader-A" (staging). Upsert idempotente en `legacy.pedidos` por la llave natural
 * compuesta de ICG (`icg_numserie`+`icg_numpedido`+`icg_n`, UNIQUE — 3 columnas, a diferencia de
 * `CotizacionStagingLoader` que usa 4 porque PEDVENTACAB no versiona). Re-correr el mismo extracto
 * dos veces nunca duplica ni pisa mal:
 *
 *  - `legacy.pedidos`: upsert por la llave compuesta. `legacy_cliente_id`/`orphan` NUNCA se
 *    incluyen en el `$update` de `upsert()` — así una segunda corrida jamás resetea el link/estado
 *    que puso `PedidoMatcher` en una corrida anterior (mismo criterio que `CotizacionStagingLoader`).
 *  - `legacy.pedido_items`: PEDVENTALIN no declara una llave natural propia (ver docblock de la
 *    migración) — en vez de upsert por columna única, este Loader usa **delete-then-insert scoped
 *    a las cabeceras de ESTA corrida** (nunca a todo el historial de items): borra todas las
 *    líneas de las cabeceras presentes en el extracto actual, luego inserta el set fresco. Correr
 *    el mismo extracto dos veces produce exactamente el mismo conjunto final de filas —
 *    idempotente por resultado, no por upsert de columna única.
 *
 * Requiere tenant activo (`Tenant::current()`, ver `getTable()` de cada modelo) — el llamador
 * (`App\Modules\Legacy\Console\Commands\LegacySyncPedidosCommand`) es responsable de `makeCurrent()` antes de
 * invocar `load()`/`loadPaginated()`.
 *
 * `loadPaginated()` agregado 2026-08-21 — mismo fix preventivo que
 * `App\Modules\Legacy\Services\CotizacionStagingLoader::loadPaginated()` tras el OOM real de
 * SCRUM-757 (ver su docblock). `load()` sigue existiendo tal cual para datasets acotados (todos
 * los tests existentes lo usan sin cambios).
 */
final class PedidoStagingLoader
{
    /**
     * Postgres tiene un límite duro de 65535 parámetros bindeados por statement (§13.3 del
     * diseño). Mismo margen que `CotizacionStagingLoader`/`ClienteStagingLoader`/
     * `ProductoStagingLoader` — 500 deja de sobra para cualquiera de las tablas de esta entidad
     * (hasta 3 tuplas por fila en el `whereRaw` de resolución de ids → 500*3=1500 params, muy por
     * debajo del límite).
     */
    private const CHUNK_SIZE = 500;

    /**
     * Envuelto en su propia transacción — mismo criterio que `CotizacionStagingLoader::load()`
     * (hallazgo Pre-QA SCRUM-750/755): un fallo a mitad de camino deja la conexión Postgres limpia
     * para que `legacy.sync_runs` pueda registrar la corrida fallida.
     *
     * @return array{pedidos: int, items: int, pedido_ids: list<int>}
     */
    public function load(ExtractedPedidos $extracted): array
    {
        return DB::transaction(function () use ($extracted): array {
            $idsByKey = $this->stagePedidos($extracted->pedidos);
            $this->deleteExistingItems(array_values($idsByKey));
            $itemsCount = $this->insertItemsPage($extracted->items, $idsByKey);

            return [
                'pedidos'    => count($extracted->pedidos),
                'items'      => $itemsCount,
                'pedido_ids' => array_values($idsByKey),
            ];
        });
    }

    /**
     * Entrada paginada para volumen real (ver docblock de la clase) — nunca materializa más de UNA
     * página de items en memoria a la vez. Misma atomicidad que `load()` (una sola transacción para
     * toda la corrida, cabeceras + todas las páginas de items).
     *
     * @param  list<PedidoRow>                             $pedidoRows
     * @param  callable(int, int): list<PedidoItemRow>     $itemPageFetcher ($offset, $limit) => página, [] = fin
     * @return array{pedidos: int, items: int, pedido_ids: list<int>}
     */
    public function loadPaginated(array $pedidoRows, callable $itemPageFetcher, int $pageSize = 20000): array
    {
        return DB::transaction(function () use ($pedidoRows, $itemPageFetcher, $pageSize): array {
            $idsByKey = $this->stagePedidos($pedidoRows);
            $this->deleteExistingItems(array_values($idsByKey));

            $itemsCount = 0;
            $offset     = 0;
            while (true) {
                /** @var list<PedidoItemRow> $page */
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
                'pedidos'    => count($pedidoRows),
                'items'      => $itemsCount,
                'pedido_ids' => array_values($idsByKey),
            ];
        });
    }

    /**
     * Upsert de cabeceras + resolución de sus ids internos — compartido por `load()`/
     * `loadPaginated()`.
     *
     * @param  list<PedidoRow>     $rows
     * @return array<string, int>  llave compuesta (string) => id interno
     */
    private function stagePedidos(array $rows): array
    {
        $this->upsertPedidos($rows, now());

        return $this->resolvePedidoIds(self::compositeKeysFrom($rows));
    }

    /**
     * Mismo cálculo que `ExtractedPedidos::compositeKeys()`, pero operando directo sobre un
     * `list<PedidoRow>` — `loadPaginated()` no siempre tiene un `ExtractedPedidos` completo a mano.
     *
     * @param  list<PedidoRow> $rows
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private static function compositeKeysFrom(array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = ExtractedPedidos::compositeKeyString($row->icgNumserie, $row->icgNumpedido, $row->icgN);
            $seen[$key] = [$row->icgNumserie, $row->icgNumpedido, $row->icgN];
        }

        return array_values($seen);
    }

    /** @param list<PedidoRow> $rows */
    private function upsertPedidos(array $rows, Carbon $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = array_map(static fn (PedidoRow $row): array => [
            'icg_numserie'       => $row->icgNumserie,
            'icg_numpedido'      => $row->icgNumpedido,
            'icg_n'              => $row->icgN,
            'icg_codcliente'     => $row->icgCodcliente,
            'fechapedido'        => $row->fechapedido?->format('Y-m-d H:i:s'),
            'fechaentrega'       => $row->fechaentrega?->format('Y-m-d H:i:s'),
            'totneto'            => $row->totneto,
            'icg_codvendedor'    => $row->icgCodvendedor,
            'idestado'           => $row->idestado,
            'icg_seriealbaran'   => $row->icgSeriealbaran,
            'icg_numeroalbaran'  => $row->icgNumeroalbaran,
            // SCRUM-842 — viene del Extractor en cada corrida (no del Matcher), sí va en el
            // $update para que una segunda corrida la refresque si cambió en ICG.
            'comision'           => $row->comision,
            'created_at'         => $now,
        ], $rows);

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            Pedido::query()->upsert(
                $chunk,
                ['icg_numserie', 'icg_numpedido', 'icg_n'],
                [
                    'icg_codcliente', 'fechapedido', 'fechaentrega', 'totneto', 'icg_codvendedor',
                    'idestado', 'icg_seriealbaran', 'icg_numeroalbaran', 'comision',
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
     * @param  list<array{0: string, 1: int, 2: int}> $keys
     * @return array<string, int> llave compuesta (string) => id interno
     */
    private function resolvePedidoIds(array $keys): array
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

            $rows = Pedido::query()
                ->select(['id', 'icg_numserie', 'icg_numpedido', 'icg_n'])
                ->whereRaw("(icg_numserie, icg_numpedido, icg_n) IN ({$placeholders})", $bindings)
                ->get();

            foreach ($rows as $row) {
                $compositeKey = ExtractedPedidos::compositeKeyString(
                    (string) $row->icg_numserie,
                    (int) $row->icg_numpedido,
                    (int) $row->icg_n,
                );

                $map[$compositeKey] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * Borra todas las líneas de las cabeceras de ESTA corrida (nunca todo el historial) — ver
     * docblock de la clase para el porqué de este patrón en vez de upsert por columna única.
     * Se corre UNA sola vez por corrida (antes de la primera página), nunca por página.
     *
     * @param list<int> $pedidoIds
     */
    private function deleteExistingItems(array $pedidoIds): void
    {
        foreach (array_chunk($pedidoIds, self::CHUNK_SIZE) as $chunk) {
            PedidoItem::query()->whereIn('legacy_pedido_id', $chunk)->delete();
        }
    }

    /**
     * Inserta UNA página/lote de items — llamable repetidas veces por `loadPaginated()` sin volver
     * a borrar nada (eso ya lo hizo `deleteExistingItems()` una sola vez antes del loop).
     *
     * @param list<PedidoItemRow> $rows
     * @param array<string, int>  $idsByKey llave compuesta de la cabecera (string) => id interno
     */
    private function insertItemsPage(array $rows, array $idsByKey): int
    {
        if ($rows === []) {
            return 0;
        }

        $values = [];
        foreach ($rows as $row) {
            $compositeKey = ExtractedPedidos::compositeKeyString(
                $row->icgNumserie,
                $row->icgNumpedido,
                $row->icgN,
            );

            $legacyPedidoId = $idsByKey[$compositeKey] ?? null;
            if ($legacyPedidoId === null) {
                // Huérfano dentro de esta corrida (la cabecera correspondiente no está en el
                // extracto actual) — se descarta, nunca rompe el resto (§13.2 del diseño, tolerar
                // huérfanos siempre).
                continue;
            }

            $values[] = [
                'legacy_pedido_id' => $legacyPedidoId,
                'icg_codarticulo'  => $row->icgCodarticulo,
                'referencia'       => $row->referencia,
                'descripcion'      => $row->descripcion,
                'cantidad'         => $row->cantidad,
                'precio'           => $row->precio,
                'total'            => $row->total,
                'unidades_pendientes' => $row->unidadesPendientes,
                'unidades_recibidas'  => $row->unidadesRecibidas,
            ];
        }

        if ($values === []) {
            return 0;
        }

        foreach (array_chunk($values, self::CHUNK_SIZE) as $chunk) {
            PedidoItem::query()->insert($chunk);
        }

        return count($values);
    }
}
