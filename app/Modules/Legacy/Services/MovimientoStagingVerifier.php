<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SCRUM-1136 F2 — lado staging de `legacy:verify-movimientos` + comparación pura contra el resumen de ICG.
 * SOLO LEE. Requiere tenant activo. Misma convención que `AlmacenStagingVerifier`: conteos exactos y suma
 * con tolerancia (ICG suma en float, staging en double).
 */
final class MovimientoStagingVerifier
{
    /** Tolerancia de la suma de UNIDADES por tipo. */
    public const UNIDADES_TOLERANCE = 0.01;

    /**
     * @return array{total: int, min_id: int|null, max_id: int|null, por_tipo: array<string, array{filas: int, unidades: float}>}
     */
    public function resumen(): array
    {
        $rows = DB::table($this->table())
            ->selectRaw("COALESCE(tipo, '') AS tipo, COUNT(*) AS filas, COALESCE(SUM(unidades), 0) AS unidades, MIN(icg_id) AS min_id, MAX(icg_id) AS max_id")
            ->groupByRaw("COALESCE(tipo, '')")
            ->get();

        $grupos = [];
        foreach ($rows as $row) {
            $grupos[] = (array) $row;
        }

        return self::agrupar($grupos);
    }

    /**
     * Pura. Resume filas agrupadas por tipo (`tipo`, `filas`, `unidades`, `min_id`, `max_id`).
     *
     * @param list<array<string, mixed>> $grupos
     * @return array{total: int, min_id: int|null, max_id: int|null, por_tipo: array<string, array{filas: int, unidades: float}>}
     */
    public static function agrupar(array $grupos): array
    {
        $total   = 0;
        $min     = null;
        $max     = null;
        $porTipo = [];
        foreach ($grupos as $g) {
            $tipo            = trim((string) ($g['tipo'] ?? ''));
            $filas           = (int) ($g['filas'] ?? 0);
            $total          += $filas;
            $porTipo[$tipo]  = [
                'filas'    => ($porTipo[$tipo]['filas'] ?? 0) + $filas,
                'unidades' => ($porTipo[$tipo]['unidades'] ?? 0.0) + (is_numeric($g['unidades'] ?? null) ? (float) $g['unidades'] : 0.0),
            ];
            if (is_numeric($g['min_id'] ?? null)) {
                $min = $min === null ? (int) $g['min_id'] : min($min, (int) $g['min_id']);
            }
            if (is_numeric($g['max_id'] ?? null)) {
                $max = $max === null ? (int) $g['max_id'] : max($max, (int) $g['max_id']);
            }
        }
        ksort($porTipo);

        return ['total' => $total, 'min_id' => $min, 'max_id' => $max, 'por_tipo' => $porTipo];
    }

    /**
     * Pura. Compara el resumen de ICG contra el de staging.
     *
     * @param array{total: int, min_id: int|null, max_id: int|null, por_tipo: array<string, array{filas: int, unidades: float}>} $icg
     * @param array{total: int, min_id: int|null, max_id: int|null, por_tipo: array<string, array{filas: int, unidades: float}>} $staging
     * @return array{
     *     global: list<array{concepto: string, icg: int|null, staging: int|null, ok: bool}>,
     *     tipos: list<array{tipo: string, icg_filas: int, staging_filas: int, icg_unidades: float, staging_unidades: float, ok: bool}>,
     *     ok: bool
     * }
     */
    public static function compare(array $icg, array $staging): array
    {
        $globalRows = [];
        $allOk      = true;
        foreach (['total' => 'Total de filas', 'min_id' => 'MIN(ID)', 'max_id' => 'MAX(ID)'] as $key => $label) {
            $ok           = $icg[$key] === $staging[$key];
            $allOk        = $allOk && $ok;
            $globalRows[] = ['concepto' => $label, 'icg' => $icg[$key], 'staging' => $staging[$key], 'ok' => $ok];
        }

        $tipos = array_unique([...array_keys($icg['por_tipo']), ...array_keys($staging['por_tipo'])]);
        sort($tipos);

        $tipoRows = [];
        foreach ($tipos as $tipo) {
            $i  = $icg['por_tipo'][$tipo] ?? ['filas' => 0, 'unidades' => 0.0];
            $s  = $staging['por_tipo'][$tipo] ?? ['filas' => 0, 'unidades' => 0.0];
            $ok = $i['filas'] === $s['filas'] && abs($i['unidades'] - $s['unidades']) <= self::UNIDADES_TOLERANCE;
            $allOk = $allOk && $ok;
            $tipoRows[] = [
                'tipo' => (string) $tipo, 'icg_filas' => $i['filas'], 'staging_filas' => $s['filas'],
                'icg_unidades' => $i['unidades'], 'staging_unidades' => $s['unidades'], 'ok' => $ok,
            ];
        }

        return ['global' => $globalRows, 'tipos' => $tipoRows, 'ok' => $allOk];
    }

    private function table(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new RuntimeException('MovimientoStagingVerifier requiere un tenant activo.');
        }

        return "{$tenant->slug}_legacy.movimientos";
    }
}
