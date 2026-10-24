<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Services;

use App\Modules\Integrations\Lafayette\Data\MappedLafayetteProducto;
use App\Modules\Integrations\Lafayette\Exceptions\FilaRechazadaException;
use App\Modules\Integrations\Lafayette\Models\LafayetteProducto;
use App\Modules\Integrations\Lafayette\Models\LafayetteSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SCRUM-990 — carga el espejo `bodega.lafayette_productos`. Reemplaza al loader viejo, que escribía en el catálogo
 * compartido y en `product_warehouse_stock` (asignaba proveedor a productos ICG, los sobrescribía y creaba 25K filas de
 * stock ajeno en una bodega de Atlantic). Este loader NO toca `catalog_products`, `product_warehouse_stock` ni
 * `warehouses`: solo su tabla.
 *
 * - UPSERT por `lafayette_id` en lotes (`config('lafayette.lote_upsert')`), con
 *   `INSERT ... ON CONFLICT (lafayette_id) DO UPDATE ... WHERE (campos) IS DISTINCT FROM (...)`: solo se escribe la fila
 *   que cambió, así que una segunda corrida sin cambios escribe 0 filas (sin WAL inflado ni `updated_at` alterado).
 *   `RETURNING (xmax = 0)` distingue insertadas de actualizadas; las omitidas por el `WHERE` no se devuelven = sin cambios.
 *   `fuente_actualizado_at` (el `cache_updated_at` de la fuente) NO entra en esa comparación: Lafayette reconstruye su caché completa antes de cada corrida (en
 *   prod, todas las filas traen una marca nueva) y, comparándola, cada corrida contaba las ~25.8K filas como actualizadas sin que cambiara ningún dato de negocio.
 *   Sí está en el SET: cuando la fila cambia por otro campo (o reaparece), `fuente_actualizado_at` toma el valor nuevo. Por eso significa "última vez que
 *   cambió el dato de esa fila en la fuente", no "última reconstrucción de caché". La fecha de última sincronización que ve el usuario sale de la corrida
 *   (`LafayetteSyncRun`), no de esta columna.
 * - Reaparición: un producto marcado `fuera_de_fuente_at` que vuelve a venir limpia la marca (y cuenta como actualizado).
 * - Desaparición: tras cargar, los productos vigentes que NO vinieron se marcan `fuera_de_fuente_at` (se ocultan, NUNCA se
 *   borran). GUARDA: si la extracción viene vacía o por debajo de `guarda_extraccion_min_ratio` de la última corrida
 *   exitosa, NO se marca nada (una vista vacía o recortada no debe ocultar el catálogo) y se devuelve `guarda_activada`.
 * - Todo en UNA transacción: o entra la corrida completa o nada.
 */
final class LafayetteProductoLoader
{
    public function __construct(private readonly LafayetteSettings $settings)
    {
    }

    /**
     * @param list<MappedLafayetteProducto> $mapped
     * @return array{unicos: int, insertados: int, actualizados: int, sin_cambios: int, fuera_de_fuente: int, rechazadas: int, guarda_activada: bool}
     */
    public function load(array $mapped): array
    {
        // La fuente no garantiza unicidad de `id` (es una vista): `ON CONFLICT` no puede tocar la misma fila dos veces
        // en un mismo INSERT, así que se deduplica en memoria (gana la última aparición).
        $unique = [];
        foreach ($mapped as $item) {
            $unique[$item->lafayetteId] = $item;
        }
        $unique = array_values($unique);

        $table = (new LafayetteProducto())->getTable();
        $batchSize = max(1, (int) config('lafayette.lote_upsert', 1000));
        $guardRatio = $this->settings->guardaMinRatio();
        // Línea base: SOLO corridas `success`. Una corrida `partial` (guarda activada con una extracción recortada) no puede convertirse en la
        // nueva línea base: la siguiente corrida igual de recortada ya no dispararía la guarda y ocultaría el resto del catálogo.
        $previous = LafayetteSyncRun::query()
            ->where('status', LafayetteSyncRun::STATUS_SUCCESS)
            ->orderByDesc('id')
            ->value('extraidos');

        return DB::transaction(function () use ($unique, $table, $batchSize, $guardRatio, $previous): array {
            $now = now()->format('Y-m-d H:i:sP');
            $inserted = 0;
            $updated = 0;
            $rejected = 0;

            foreach (array_chunk($unique, $batchSize) as $chunk) {
                $returned = $this->upsertWithQuarantine($table, $chunk, $now);
                $inserted += $returned['insertados'];
                $updated += $returned['actualizados'];
                $rejected += $returned['rechazadas'];
            }

            $total = count($unique);
            $unchanged = $total - $inserted - $updated - $rejected;

            $guardActive = $total === 0 || ($previous !== null && (int) $previous > 0 && $total < (int) $previous * $guardRatio);
            $gone = 0;
            if (! $guardActive) {
                $gone = $this->markOutOfSource($table, array_map(static fn (MappedLafayetteProducto $p): string => $p->lafayetteId, $unique), $now);
            }

            return [
                'unicos'          => $total,
                'insertados'      => $inserted,
                'actualizados'    => $updated,
                'sin_cambios'     => $unchanged,
                'fuera_de_fuente' => $gone,
                'rechazadas'      => $rejected,
                'guarda_activada' => $guardActive,
            ];
        });
    }

    /**
     * Un lote dentro de un SAVEPOINT. Si la BD lo rechaza (algo que el mapper no previó), se reintenta fila por fila, cada una en su savepoint: la
     * que falla va a cuarentena (se cuenta y se registra solo su id, nunca su contenido) y el resto de la sincronización continúa. Sin el
     * savepoint, el primer error abortaría la transacción entera de Postgres.
     *
     * @param list<MappedLafayetteProducto> $chunk
     * @return array{insertados: int, actualizados: int, rechazadas: int}
     */
    private function upsertWithQuarantine(string $table, array $chunk, string $now): array
    {
        try {
            $r = DB::transaction(fn (): array => $this->upsertChunk($table, $chunk, $now));

            return $r + ['rechazadas' => 0];
        } catch (\Throwable) {
            $inserted = 0;
            $updated = 0;
            $rejected = 0;
            foreach ($chunk as $row) {
                try {
                    $r = DB::transaction(fn (): array => $this->upsertChunk($table, [$row], $now));
                    $inserted += $r['insertados'];
                    $updated += $r['actualizados'];
                } catch (\Throwable $e) {
                    $rejected++;
                    Log::warning('lafayette.fila_rechazada_por_bd', [
                        'lafayette_id' => $row->lafayetteId,
                        'motivo'       => FilaRechazadaException::RECHAZADA_POR_BD,
                        'error'        => $e::class,
                        'sqlstate'     => $e instanceof \Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? null) : null,
                    ]);
                }
            }

            return ['insertados' => $inserted, 'actualizados' => $updated, 'rechazadas' => $rejected];
        }
    }

    /**
     * @param list<MappedLafayetteProducto> $chunk
     * @return array{insertados: int, actualizados: int}
     */
    private function upsertChunk(string $table, array $chunk, string $now): array
    {
        $placeholders = [];
        $bindings = [];
        foreach ($chunk as $p) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $p->lafayetteId,
                $p->referencia,
                $p->descripcion,
                $p->marca,
                $p->categoria,
                $p->urlImagen,
                $p->disponible,
                $p->precio5,
                $p->precio6,
                $p->fuenteActualizadoAt?->format('Y-m-d H:i:sP'),
                $now,
                $now,
            );
        }

        $values = implode(', ', $placeholders);

        $rows = DB::select(<<<SQL
            INSERT INTO {$table} AS t
                (lafayette_id, referencia, descripcion, marca, categoria, url_imagen, disponible, precio_5, precio_6,
                 fuente_actualizado_at, created_at, updated_at)
            VALUES {$values}
            ON CONFLICT (lafayette_id) DO UPDATE SET
                referencia = EXCLUDED.referencia,
                descripcion = EXCLUDED.descripcion,
                marca = EXCLUDED.marca,
                categoria = EXCLUDED.categoria,
                url_imagen = EXCLUDED.url_imagen,
                disponible = EXCLUDED.disponible,
                precio_5 = EXCLUDED.precio_5,
                precio_6 = EXCLUDED.precio_6,
                fuente_actualizado_at = EXCLUDED.fuente_actualizado_at,
                fuera_de_fuente_at = NULL,
                updated_at = EXCLUDED.updated_at
            WHERE (t.referencia, t.descripcion, t.marca, t.categoria, t.url_imagen, t.disponible, t.precio_5, t.precio_6, t.fuera_de_fuente_at)
                IS DISTINCT FROM
                  (EXCLUDED.referencia, EXCLUDED.descripcion, EXCLUDED.marca, EXCLUDED.categoria, EXCLUDED.url_imagen,
                   EXCLUDED.disponible, EXCLUDED.precio_5, EXCLUDED.precio_6, NULL::timestamptz)
            RETURNING (xmax = 0) AS inserted
            SQL, $bindings, false);

        $inserted = 0;
        foreach ($rows as $row) {
            if ($row->inserted) {
                $inserted++;
            }
        }

        return ['insertados' => $inserted, 'actualizados' => count($rows) - $inserted];
    }

    /**
     * Marca como fuera de la fuente los productos vigentes que no vinieron en esta corrida. Un solo parámetro (arreglo
     * literal de Postgres) en vez de 25.8K: acotado y sin tocar el límite de parámetros de PDO.
     *
     * @param list<string> $seenIds
     */
    private function markOutOfSource(string $table, array $seenIds, string $now): int
    {
        $literal = '{' . implode(',', array_map(
            static fn (string $id): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $id) . '"',
            $seenIds,
        )) . '}';

        return DB::affectingStatement(
            "UPDATE {$table} SET fuera_de_fuente_at = ?, updated_at = ? WHERE fuera_de_fuente_at IS NULL AND lafayette_id <> ALL (?::varchar[])",
            [$now, $now, $literal],
        );
    }
}
