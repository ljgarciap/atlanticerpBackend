<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Support\AlmacenEntidades;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F1 — "Loader-A" (staging) de almacenes/stock/costos/traspasos/inventarios. Upsert idempotente
 * por la llave natural de ICG (UNIQUE en cada tabla): volver a correr no duplica ni pisa mal.
 *
 * A diferencia de `PagoStagingLoader` (una sola transacción para toda la corrida), acá CADA PÁGINA se
 * confirma en su propia transacción: STOCKS son ~181.727 filas y una caída de la conexión a ICG a mitad de
 * camino debe dejar lo ya cargado (la corrida queda `partial` y la siguiente es idempotente). Nunca
 * materializa más de UNA página en memoria (host de 2 GB con historial de OOM). Si el llamador envuelve la
 * llamada en su propia transacción (modo `--dry-run`), las de página se vuelven savepoints y todo se revierte.
 *
 * Requiere tenant activo (`Tenant::current()`): el llamador hace `makeCurrent()`.
 */
final class AlmacenStagingLoader
{
    /** Tope de parámetros de Postgres por statement: 65535 (diseño §13.3). Se deja margen. */
    private const MAX_PARAMS = 30000;

    /** Tope de filas por INSERT…ON CONFLICT. */
    private const MAX_ROWS_PER_CHUNK = 500;

    /**
     * @param callable(int, int): list<array<string, mixed>> $pageFetcher ($offset, $limit) => página; [] = fin
     * @param (callable(int, int): void)|null                $onPage      ($extraidos, $cargados) acumulados tras cada página
     * @return array{extraidos: int, cargados: int, paginas: int}
     */
    public function loadPaginated(string $entidad, callable $pageFetcher, int $pageSize = 5000, ?callable $onPage = null): array
    {
        if ($pageSize < 1) {
            throw new RuntimeException('pageSize debe ser >= 1.');
        }

        $extraidos = 0;
        $cargados  = 0;
        $paginas   = 0;
        $offset    = 0;

        while (true) {
            $page = $pageFetcher($offset, $pageSize);
            if ($page === []) {
                break;
            }

            $extraidos += count($page);
            $paginas++;
            $cargados += DB::transaction(fn (): int => $this->upsertPage($entidad, $page));

            if ($onPage !== null) {
                $onPage($extraidos, $cargados);
            }

            if (count($page) < $pageSize) {
                break;
            }

            $offset += $pageSize;
        }

        return ['extraidos' => $extraidos, 'cargados' => $cargados, 'paginas' => $paginas];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return int filas únicas upserteadas
     */
    private function upsertPage(string $entidad, array $rows): int
    {
        $keys = AlmacenEntidades::keys($entidad);

        // Dos filas de ICG que tras el trim comparten llave harían fallar el ON CONFLICT ("cannot affect
        // row a second time"); gana la última.
        $unique = [];
        foreach ($rows as $row) {
            $id = implode("\x1F", array_map(static fn (string $k): string => (string) ($row[$k] ?? ''), $keys));
            $unique[$id] = $row;
        }

        $now     = now();
        $uniqueRows = array_values($unique);
        $columns    = array_keys($uniqueRows[0]);
        $update     = array_values(array_diff($columns, $keys));
        $update[]   = 'updated_at';

        $values = array_map(static fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now], $uniqueRows);

        $perChunk = max(1, min(self::MAX_ROWS_PER_CHUNK, intdiv(self::MAX_PARAMS, count($columns) + 2)));
        $table    = $this->qualifiedTable($entidad);

        foreach (array_chunk($values, $perChunk) as $chunk) {
            DB::table($table)->upsert($chunk, $keys, $update);
        }

        return count($unique);
    }

    private function qualifiedTable(string $entidad): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new RuntimeException('AlmacenStagingLoader requiere un tenant activo.');
        }

        return "{$tenant->slug}_legacy." . AlmacenEntidades::tabla($entidad);
    }
}
