<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Extractors;

use App\Modules\Legacy\Contracts\MovimientoVerificationExtractorContract;
use App\Modules\Legacy\Services\MovimientoStagingVerifier;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1136 F2 — agregados del lado ICG de MOVIMENTS para `legacy:verify-movimientos`. Solo SELECT, un único
 * escaneo agrupado por TIPO (el total y el rango de ID salen de esos grupos).
 */
final class MovimientoVerificationExtractor implements MovimientoVerificationExtractorContract
{
    public function __construct(private readonly string $connection = 'legacy_icg')
    {
    }

    public function resumen(): array
    {
        return self::mapRows(DB::connection($this->connection)->select(self::resumenQuery()));
    }

    public static function resumenQuery(): string
    {
        return 'SELECT LTRIM(RTRIM(TIPO)) AS TIPO, COUNT(*) AS FILAS, SUM(CAST(UNIDADES AS float)) AS UNIDADES, '
            . 'MIN(ID) AS MIN_ID, MAX(ID) AS MAX_ID FROM MOVIMENTS GROUP BY LTRIM(RTRIM(TIPO))';
    }

    /**
     * @param array<int, object|array<string, mixed>> $rows
     * @return array{total: int, min_id: int|null, max_id: int|null, por_tipo: array<string, array{filas: int, unidades: float}>}
     */
    public static function mapRows(array $rows): array
    {
        $grupos = [];
        foreach ($rows as $row) {
            $row      = array_change_key_case((array) $row, CASE_UPPER);
            $grupos[] = [
                'tipo'     => $row['TIPO'] ?? '',
                'filas'    => $row['FILAS'] ?? 0,
                'unidades' => $row['UNIDADES'] ?? 0,
                'min_id'   => $row['MIN_ID'] ?? null,
                'max_id'   => $row['MAX_ID'] ?? null,
            ];
        }

        return MovimientoStagingVerifier::agrupar($grupos);
    }
}
