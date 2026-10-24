<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Support\AlmacenEntidades;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F1 — lado staging de `legacy:verify-almacenes` + comparación pura contra los agregados de
 * ICG. SOLO LEE: nunca escribe en staging ni en ICG. Requiere tenant activo.
 */
final class AlmacenStagingVerifier
{
    /** Tolerancia de la suma de STOCK (ICG la suma en float; staging en double). */
    public const STOCK_TOLERANCE = 0.01;

    /**
     * Filas y suma de `stock` por almacén en staging.
     *
     * @return array<string, array{filas: int, stock: float}>
     */
    public function stockPorAlmacen(): array
    {
        $rows = DB::table($this->table('stock_almacen'))
            ->selectRaw('codalmacen, COUNT(*) AS filas, COALESCE(SUM(stock), 0) AS stock')
            ->groupBy('codalmacen')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->codalmacen] = ['filas' => (int) $row->filas, 'stock' => (float) $row->stock];
        }

        return $out;
    }

    /**
     * Conteos de staging por entidad (todas las de `AlmacenEntidades`).
     *
     * @return array<string, int>
     */
    public function conteos(): array
    {
        $out = [];
        foreach (AlmacenEntidades::todas() as $entidad) {
            $out[$entidad] = DB::table($this->table(AlmacenEntidades::tabla($entidad)))->count();
        }

        return $out;
    }

    /**
     * Almacenes presentes en `stock_almacen` sin fila en `almacenes` (caso conocido: A1), con su cantidad
     * de filas de stock.
     *
     * @return array<string, int> codalmacen => filas
     */
    public function huerfanos(): array
    {
        $rows = DB::table($this->table('stock_almacen') . ' AS s')
            ->leftJoin($this->table('almacenes') . ' AS a', 'a.codalmacen', '=', 's.codalmacen')
            ->whereNull('a.id')
            ->groupBy('s.codalmacen')
            ->selectRaw('s.codalmacen AS codalmacen, COUNT(*) AS filas')
            ->orderBy('s.codalmacen')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->codalmacen] = (int) $row->filas;
        }

        return $out;
    }

    /**
     * Pura. Compara ICG vs staging.
     *
     * @param array<string, array{filas: int, stock: float}> $icgStock
     * @param array<string, array{filas: int, stock: float}> $stagingStock
     * @param array<string, int>                             $icgConteos     almacenes, costo_almacen, traspasos, inventarios
     * @param array<string, int>                             $stagingConteos
     * @return array{stock: list<array{codalmacen: string, icg_filas: int, staging_filas: int, icg_stock: float, staging_stock: float, ok: bool}>, conteos: list<array{entidad: string, icg: int, staging: int, ok: bool}>, ok: bool}
     */
    public static function compare(array $icgStock, array $stagingStock, array $icgConteos, array $stagingConteos): array
    {
        $codes = array_unique([...array_keys($icgStock), ...array_keys($stagingStock)]);
        sort($codes);

        $stockRows = [];
        $allOk     = true;
        foreach ($codes as $code) {
            $icg     = $icgStock[$code] ?? ['filas' => 0, 'stock' => 0.0];
            $staging = $stagingStock[$code] ?? ['filas' => 0, 'stock' => 0.0];
            $ok      = $icg['filas'] === $staging['filas']
                && abs($icg['stock'] - $staging['stock']) <= self::STOCK_TOLERANCE;
            $allOk = $allOk && $ok;
            $stockRows[] = [
                'codalmacen'    => (string) $code,
                'icg_filas'     => $icg['filas'],
                'staging_filas' => $staging['filas'],
                'icg_stock'     => $icg['stock'],
                'staging_stock' => $staging['stock'],
                'ok'            => $ok,
            ];
        }

        $conteoRows = [];
        foreach ($icgConteos as $entidad => $icg) {
            $staging = $stagingConteos[$entidad] ?? 0;
            $ok      = $icg === $staging;
            $allOk   = $allOk && $ok;
            $conteoRows[] = ['entidad' => $entidad, 'icg' => $icg, 'staging' => $staging, 'ok' => $ok];
        }

        return ['stock' => $stockRows, 'conteos' => $conteoRows, 'ok' => $allOk];
    }

    private function table(string $name): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new RuntimeException('AlmacenStagingVerifier requiere un tenant activo.');
        }

        return "{$tenant->slug}_legacy.{$name}";
    }
}
