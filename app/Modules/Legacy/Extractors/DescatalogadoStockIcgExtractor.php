<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\DescatalogadoStockIcgExtractorContract;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1136 F5 (RF5) — agregados de solo lectura de ICG para `legacy:verify-descatalogados`. Mismo patrón que `AlmacenVerificationExtractor` (F1): solo SELECT con `COUNT`/`SUM`,
 * consultas y mapeo `static` y puros. Mismo filtro que `ProductoDescatalogadoExtractor` (`DESCATALOGADO='T'`, `STOCK <> 0`).
 */
final class DescatalogadoStockIcgExtractor implements DescatalogadoStockIcgExtractorContract
{
    private const FROM = "FROM STOCKS s INNER JOIN ARTICULOS a ON a.CODARTICULO = s.CODARTICULO WHERE a.DESCATALOGADO = 'T' AND s.STOCK <> 0";

    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function resumen(): array
    {
        $db = DB::connection($this->connection);

        return [
            'articulos'   => self::mapCountRow($db->select(self::articulosQuery())),
            'por_almacen' => self::mapStockRows($db->select(self::stockQuery())),
        ];
    }

    public static function articulosQuery(): string
    {
        return 'SELECT COUNT(DISTINCT s.CODARTICULO) AS N ' . self::FROM;
    }

    public static function stockQuery(): string
    {
        return 'SELECT LTRIM(RTRIM(s.CODALMACEN)) AS CODALMACEN, COUNT(DISTINCT s.CODARTICULO) AS ARTICULOS, SUM(CAST(s.STOCK AS float)) AS STOCK '
            . self::FROM . ' GROUP BY LTRIM(RTRIM(s.CODALMACEN))';
    }

    /**
     * @param  array<int, object|array<string, mixed>> $rows
     * @return array<string, array{articulos: int, stock: float}>
     */
    public static function mapStockRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $out[trim((string) ($row['CODALMACEN'] ?? ''))] = [
                'articulos' => (int) ($row['ARTICULOS'] ?? 0),
                'stock'     => is_numeric($row['STOCK'] ?? null) ? (float) $row['STOCK'] : 0.0,
            ];
        }

        return $out;
    }

    /** @param array<int, object|array<string, mixed>> $rows */
    public static function mapCountRow(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $row = array_change_key_case((array) $rows[0], CASE_UPPER);

        return (int) ($row['N'] ?? 0);
    }
}
