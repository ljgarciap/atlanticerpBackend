<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Support\ClienteRawEntidades;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1137 F1 — loader (staging) de la copia cruda de CLIENTES y CONTACTOSCLIENTES de ICG. Gemelo de
 * `AlmacenStagingLoader` (cada página en su propia transacción, ≤30.000 parámetros por statement, ≤500 filas
 * por INSERT, nunca más de una página en memoria) con una diferencia: el `ON CONFLICT … DO UPDATE` lleva
 * `WHERE source_hash IS DISTINCT FROM EXCLUDED.source_hash`, así una fila que no cambió en ICG no se reescribe
 * (ni siquiera `updated_at`). Eloquent/`DB::table()->upsert()` no permiten esa cláusula, por eso SQL directo
 * (con identificadores validados; los valores siempre van como bindings).
 *
 * Requiere tenant activo (`Tenant::current()`): el llamador hace `makeCurrent()`. Si el llamador envuelve la
 * llamada en su propia transacción (modo `--dry-run`), las de página se vuelven savepoints y todo se revierte.
 */
final class ClienteRawStagingLoader
{
    /** Tope de parámetros de Postgres por statement: 65535. Se deja margen. */
    private const MAX_PARAMS = 30000;

    /** Tope de filas por INSERT…ON CONFLICT. */
    private const MAX_ROWS_PER_CHUNK = 500;

    /**
     * @param callable(int, int): list<array<string, mixed>> $pageFetcher ($offset, $limit) => página; [] = fin
     * @param (callable(int, int): void)|null                $onPage      ($extraidos, $cargados) acumulados tras cada página
     * @return array{extraidos: int, cargados: int, cambiados: int, paginas: int}
     *         `cargados` = filas únicas procesadas; `cambiados` = las realmente insertadas o modificadas
     */
    public function loadPaginated(string $entidad, callable $pageFetcher, int $pageSize = 5000, ?callable $onPage = null): array
    {
        if ($pageSize < 1) {
            throw new RuntimeException('pageSize debe ser >= 1.');
        }

        $extraidos = 0;
        $cargados  = 0;
        $cambiados = 0;
        $paginas   = 0;
        $offset    = 0;

        while (true) {
            $page = $pageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            $extraidos += count($page);
            $paginas++;
            [$unicos, $afectados] = DB::transaction(fn (): array => $this->upsertPage($entidad, $page));
            $cargados  += $unicos;
            $cambiados += $afectados;

            if ($onPage !== null) {
                $onPage($extraidos, $cargados);
            }

            if (count($page) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return ['extraidos' => $extraidos, 'cargados' => $cargados, 'cambiados' => $cambiados, 'paginas' => $paginas];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{0: int, 1: int} [filas únicas, filas insertadas o modificadas]
     */
    private function upsertPage(string $entidad, array $rows): array
    {
        $keys = ClienteRawEntidades::keys($entidad);

        // Dos filas de ICG con la misma llave harían fallar el ON CONFLICT ("cannot affect row a second
        // time"); gana la última.
        $unique = [];
        foreach ($rows as $row) {
            $id = implode("\x1F", array_map(static fn (string $k): string => (string) ($row[$k] ?? ''), $keys));
            $unique[$id] = $row;
        }

        $uniqueRows = array_values($unique);
        $columns    = array_keys($uniqueRows[0]);
        foreach ($columns as $column) {
            if (preg_match('/^[a-z0-9_]+$/', $column) !== 1) {
                throw new RuntimeException("Nombre de columna de staging inválido: '{$column}'.");
            }
        }

        $now      = now();
        $allCols  = [...$columns, 'created_at', 'updated_at'];
        $perChunk = max(1, min(self::MAX_ROWS_PER_CHUNK, intdiv(self::MAX_PARAMS, count($allCols))));
        $grammar  = DB::getQueryGrammar();
        $table    = $grammar->wrapTable($this->qualifiedTable($entidad));

        $colList   = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $allCols));
        $keyList   = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $keys));
        $updateSet = implode(', ', array_map(
            static fn (string $c): string => '"' . $c . '" = EXCLUDED."' . $c . '"',
            [...array_values(array_diff($columns, $keys)), 'updated_at'],
        ));
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($allCols), '?')) . ')';

        $afectados = 0;
        foreach (array_chunk($uniqueRows, $perChunk) as $chunk) {
            $bindings = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
                $bindings[] = $now;
                $bindings[] = $now;
            }

            $sql = "INSERT INTO {$table} ({$colList}) VALUES "
                . implode(', ', array_fill(0, count($chunk), $rowPlaceholder))
                . " ON CONFLICT ({$keyList}) DO UPDATE SET {$updateSet}"
                . " WHERE {$table}.\"source_hash\" IS DISTINCT FROM EXCLUDED.\"source_hash\"";

            $afectados += DB::affectingStatement($sql, $bindings);
        }

        return [count($unique), $afectados];
    }

    private function qualifiedTable(string $entidad): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new RuntimeException('ClienteRawStagingLoader requiere un tenant activo.');
        }

        return "{$tenant->slug}_legacy." . ClienteRawEntidades::tabla($entidad);
    }
}
