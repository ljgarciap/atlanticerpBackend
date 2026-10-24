<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F2 — carga única REANUDABLE de MOVIMENTS en `legacy.movimientos`. Misma filosofía que
 * `AlmacenStagingLoader` (upsert idempotente, UNA transacción por página, una sola página en memoria), pero
 * con paginación por LLAVE: cada página pide `ID > <último icg_id>`, y la primera lee `MAX(icg_id)` ya cargado,
 * de modo que una caída a mitad de la carga deja lo confirmado y la siguiente corrida continúa desde ahí sin
 * duplicar ni saltar filas (cada página es atómica y ascendente, así que `MAX(icg_id)` nunca deja huecos
 * por debajo). Volver a correr sobre una carga completa no inserta nada.
 *
 * `$maxPaginas` limita la corrida (tandas, o ensayo `--dry-run`); null = hasta agotar ICG. Si el llamador
 * envuelve la llamada en su propia transacción (modo `--dry-run`), las de página se vuelven savepoints y todo se
 * revierte.
 *
 * ADVERTENCIA: la reanudación se basa en `MAX(icg_id)`, así que NO se deben correr dos `legacy:sync-movimientos`
 * a la vez (una podría dar por cargado lo que la otra aún no confirmó y dejar huecos). Una carga incompleta se
 * detecta con `legacy:verify-movimientos`.
 *
 * Requiere tenant activo (`Tenant::current()`): el llamador hace `makeCurrent()`.
 */
final class MovimientoStagingLoader
{
    /** Tope de parámetros de Postgres por statement: 65535 (diseño §13.3). Se deja margen. */
    private const MAX_PARAMS = 30000;

    /** Tope de filas por INSERT…ON CONFLICT. */
    private const MAX_ROWS_PER_CHUNK = 500;

    /**
     * @param callable(int, int): list<array<string, mixed>> $pageFetcher ($afterId, $limit) => página ascendente por icg_id; [] = fin
     * @param (callable(int, int): void)|null                $onPage      ($extraidos, $cargados) acumulados tras cada página
     * @return array{extraidos: int, cargados: int, paginas: int, desde_id: int, ultimo_id: int, completo: bool}
     *         `completo` = ICG no tiene más filas (false si se frenó por `$maxPaginas`)
     */
    public function load(callable $pageFetcher, int $pageSize = 5000, ?int $maxPaginas = null, ?callable $onPage = null): array
    {
        if ($pageSize < 1) {
            throw new RuntimeException('pageSize debe ser >= 1.');
        }
        if ($maxPaginas !== null && $maxPaginas < 1) {
            throw new RuntimeException('maxPaginas debe ser >= 1.');
        }

        $desde     = $this->maxCargado();
        $lastId    = $desde;
        $extraidos = 0;
        $cargados  = 0;
        $paginas   = 0;
        $completo  = false;

        while (true) {
            if ($maxPaginas !== null && $paginas >= $maxPaginas) {
                break;
            }

            $page = $pageFetcher($lastId, $pageSize);
            if ($page === []) {
                $completo = true;
                break;
            }

            $pageMax = max(array_map(static fn (array $row): int => (int) $row['icg_id'], $page));
            if ($pageMax <= $lastId) {
                // Un extractor que no avanza haría un ciclo infinito.
                throw new RuntimeException("La página de MOVIMENTS no avanzó (ID máx. {$pageMax} <= {$lastId}).");
            }

            $extraidos += count($page);
            $paginas++;
            $cargados += DB::transaction(fn (): int => $this->upsertPage($page));
            $lastId = $pageMax;

            if ($onPage !== null) {
                $onPage($extraidos, $cargados);
            }

            if (count($page) < $pageSize) {
                $completo = true;
                break;
            }
        }

        return [
            'extraidos' => $extraidos, 'cargados' => $cargados, 'paginas' => $paginas,
            'desde_id'  => $desde, 'ultimo_id' => $lastId, 'completo' => $completo,
        ];
    }

    /** Mayor `icg_id` ya cargado (0 si la tabla está vacía; los ID de ICG son >= 1). */
    public function maxCargado(): int
    {
        return (int) DB::table($this->qualifiedTable())->max('icg_id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return int filas únicas upserteadas
     */
    private function upsertPage(array $rows): int
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique[(int) $row['icg_id']] = $row;
        }

        $now        = now();
        $uniqueRows = array_values($unique);
        $columns    = array_keys($uniqueRows[0]);
        $update     = array_values(array_diff($columns, ['icg_id']));
        $update[]   = 'updated_at';

        $values = array_map(static fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now], $uniqueRows);

        $perChunk = max(1, min(self::MAX_ROWS_PER_CHUNK, intdiv(self::MAX_PARAMS, count($columns) + 2)));
        $table    = $this->qualifiedTable();

        foreach (array_chunk($values, $perChunk) as $chunk) {
            DB::table($table)->upsert($chunk, ['icg_id'], $update);
        }

        return count($unique);
    }

    private function qualifiedTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new RuntimeException('MovimientoStagingLoader requiere un tenant activo.');
        }

        return "{$tenant->slug}_legacy.movimientos";
    }
}
