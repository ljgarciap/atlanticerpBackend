<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\AlmacenVerificationExtractorContract;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1136 F1 — agregados del lado ICG para `legacy:verify-almacenes`. Solo SELECT; los conteos salen
 * de `COUNT(*)` (no de catálogos del sistema: `illumiread` no tiene VIEW DATABASE STATE). Consultas y
 * mapeo `static` y puros; solo los métodos de la interfaz tocan la conexión.
 */
final class AlmacenVerificationExtractor implements AlmacenVerificationExtractorContract
{
    /** entidad de staging => tabla de ICG (COUNT(*)). */
    private const TABLAS_CONTEO = [
        'almacenes'     => 'ALMACEN',
        'costo_almacen' => 'COSTESPORALMACEN',
        'traspasos'     => 'TRASPASOSCAB',
        'inventarios'   => 'INVENTARIOS',
    ];

    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function stockPorAlmacen(): array
    {
        return self::mapStockRows(DB::connection($this->connection)->select(self::stockQuery()));
    }

    public function conteos(): array
    {
        $db     = DB::connection($this->connection);
        $conteo = [];
        foreach (array_keys(self::TABLAS_CONTEO) as $entidad) {
            $conteo[$entidad] = self::mapCountRow($db->select(self::countQuery($entidad)));
        }

        return $conteo;
    }

    public static function stockQuery(): string
    {
        return 'SELECT LTRIM(RTRIM(CODALMACEN)) AS CODALMACEN, COUNT(*) AS FILAS, SUM(CAST(STOCK AS float)) AS STOCK '
            . 'FROM STOCKS GROUP BY LTRIM(RTRIM(CODALMACEN))';
    }

    public static function countQuery(string $entidad): string
    {
        return 'SELECT COUNT(*) AS N FROM ' . self::TABLAS_CONTEO[$entidad];
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @return array<string, array{filas: int, stock: float}>
     */
    public static function mapStockRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row, CASE_UPPER);
            $out[trim((string) ($row['CODALMACEN'] ?? ''))] = [
                'filas' => (int) ($row['FILAS'] ?? 0),
                'stock' => is_numeric($row['STOCK'] ?? null) ? (float) $row['STOCK'] : 0.0,
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
