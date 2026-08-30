<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Batch 22 (SCRUM-643→647, REQ-566) — resuelve la lista de meses de un período del selector de
 * Reportes ("3m"/"6m"/"anio"), compartido entre `InvoiceService::ventasSeries()` y
 * `CashPositionService::netFlowSeries()` para no duplicar la regla. RN2 REQ-566: "Año" es siempre
 * año-a-la-fecha (enero → mes actual), nunca 12 meses fijos hacia atrás. "3m"/"6m" siempre
 * terminan en el mes actual EN CURSO (incluido, aunque esté a mitad de mes) — nunca solo meses ya
 * cerrados.
 */
final class ReportPeriodResolver
{
    public const PERIODOS = ['hoy', '3m', '6m', 'anio'];

    /**
     * @return list<array{mes: string, anio: int, inicio: CarbonImmutable, fin: CarbonImmutable}>
     */
    public static function meses(string $periodo, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::now();

        $cantidad = match ($periodo) {
            '3m'   => 3,
            '6m'   => 6,
            'anio' => $today->month,
            default => throw new InvalidArgumentException("Período inválido para serie mensual: {$periodo}"),
        };

        $meses = [];
        for ($i = $cantidad - 1; $i >= 0; $i--) {
            $mesRef = $today->subMonthsNoOverflow($i);
            $meses[] = [
                'mes'    => self::labelMes($mesRef->month),
                'anio'   => $mesRef->year,
                'inicio' => $mesRef->startOfMonth(),
                'fin'    => $mesRef->isSameMonth($today) ? $today->endOfDay() : $mesRef->endOfMonth(),
            ];
        }

        return $meses;
    }

    private static function labelMes(int $month): string
    {
        return [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
        ][$month];
    }
}
