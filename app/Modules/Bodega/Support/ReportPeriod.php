<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Support;

use Carbon\CarbonImmutable;

/**
 * SCRUM-490 (REQ-420 RN1) — resolución del selector de período compartido de la pantalla
 * "Reportes de Bodega": el mismo `period` (mes/trimestre/año desde 1-enero) aplica a las 4
 * tarjetas a la vez, nunca individualmente (ver `BodegaReportsController`, un solo helper en vez
 * de que cada acción reimplemente el cálculo de rango).
 *
 * Mismas claves/semántica que `VentasDiseno\Services\ReportsService::periodRange()` ('month'/
 * 'quarter'/'year', trimestre = inicio del trimestre en curso, año = 1-enero) — se replica acá en
 * vez de extraerse a `Shared` porque `ReportsService::periodRange()` es un método privado interno
 * de esa clase sin abstracción propia todavía; unificar ambos en un helper común queda fuera de
 * alcance de este batch (decisión dentro del diseño aprobado, no cambia contrato de ningún ticket).
 */
final class ReportPeriod
{
    public const MONTH = 'month';

    public const QUARTER = 'quarter';

    public const YEAR = 'year';

    /** @var list<string> */
    public const VALID = [self::MONTH, self::QUARTER, self::YEAR];

    /** @return array{key: string, from: CarbonImmutable, to: CarbonImmutable} */
    public static function resolve(?string $period): array
    {
        $key = in_array($period, self::VALID, true) ? $period : self::MONTH;
        $now = CarbonImmutable::now();

        $from = match ($key) {
            self::QUARTER => $now->firstOfQuarter(),
            self::YEAR    => $now->startOfYear(),
            default       => $now->startOfMonth(),
        };

        return ['key' => $key, 'from' => $from, 'to' => $now];
    }
}
