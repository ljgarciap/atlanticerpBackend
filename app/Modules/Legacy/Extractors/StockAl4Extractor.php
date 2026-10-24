<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\StockAl4ExtractorContract;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1114 — lee SOLO `STOCKS WHERE CODALMACEN='AL4'` (una tabla, ~64.000 filas), no vuelve a leer
 * `ARTICULOS`. Mismo criterio de fila que `ProductoExtractor::articulosQuery()` (AL4 = Bodega
 * Central, única fuente de stock vendible; `TALLA`/`COLOR` siempre `'.'` — Illumination no usa esas
 * variantes), así que un artículo aparece como máximo una vez. Solo SELECT contra ICG.
 *
 * Separación consulta / mapeo / conexión igual que el resto de extractores: `stockQuery()` y
 * `mapRows()` son puros y testeables sin red; `extract()` es el único que toca la conexión.
 */
final class StockAl4Extractor implements StockAl4ExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function extract(): array
    {
        return self::mapRows(DB::connection($this->connection)->select(self::stockQuery()));
    }

    public static function stockQuery(): string
    {
        return "SELECT CODARTICULO, STOCK FROM STOCKS WHERE CODALMACEN = 'AL4' AND TALLA = '.' AND COLOR = '.'";
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @return array<int, float>
     */
    public static function mapRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (! isset($row['CODARTICULO']) || ! is_numeric($row['CODARTICULO'])) {
                continue;
            }
            // STOCK nulo en ICG se trata como "sin movimiento": igual que no tener fila.
            $map[(int) $row['CODARTICULO']] = is_numeric($row['STOCK'] ?? null) ? (float) $row['STOCK'] : 0.0;
        }

        return $map;
    }
}
