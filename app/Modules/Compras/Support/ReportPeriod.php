<?php

declare(strict_types=1);

namespace App\Modules\Compras\Support;

use Carbon\CarbonImmutable;

/**
 * SCRUM-262 (REQ-199) — contrato de período compartido por los 6 paneles de Reportes. No existía
 * ningún helper de rango de fechas reusable en el codebase (VentasDiseno y CRM tienen cada uno el
 * suyo, sin acuerdo de forma); este es el primero para Compras.
 *
 * `granularity` define el TAMAÑO del bucket (mes/trimestre/año), `anchor` (opcional, default
 * ahora) define DÓNDE cae ese bucket — permite mostrar tanto el período en curso como períodos
 * cerrados anteriores, algo que ningún panel de este proyecto soportaba todavía.
 */
final class ReportPeriod
{
    public const GRANULARITIES = ['month', 'quarter', 'year'];

    private function __construct(
        public readonly string $granularity,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $isCurrent,
    ) {
    }

    public static function resolve(string $granularity, ?string $anchor = null): self
    {
        $anchorDate = $anchor !== null ? CarbonImmutable::parse($anchor) : CarbonImmutable::now();
        $now = CarbonImmutable::now();

        [$start, $end] = match ($granularity) {
            'quarter' => [$anchorDate->firstOfQuarter(), $anchorDate->lastOfQuarter()],
            'year'    => [$anchorDate->startOfYear(), $anchorDate->endOfYear()],
            default   => [$anchorDate->startOfMonth(), $anchorDate->endOfMonth()],
        };

        return new self($granularity, $start, $end, $start->lte($now) && $end->gte($now));
    }

    /**
     * Últimos `$count` buckets de esta granularidad, terminando en el período en curso —
     * REQ-200: "gráfico de barras... incluyendo el período en curso".
     *
     * @return list<self>
     */
    public static function lookback(string $granularity, int $count): array
    {
        $current = self::resolve($granularity);
        $periods = [$current];

        $cursor = $current->start;
        for ($i = 1; $i < $count; $i++) {
            $cursor = match ($granularity) {
                'quarter' => $cursor->subQuarter(),
                'year'    => $cursor->subYear(),
                default   => $cursor->subMonth(),
            };
            $periods[] = self::resolve($granularity, $cursor->toDateString());
        }

        return array_reverse($periods);
    }

    public function label(): string
    {
        return match ($this->granularity) {
            'quarter' => 'Q'.$this->start->quarter.' '.$this->start->year,
            'year'    => (string) $this->start->year,
            default   => ucfirst($this->start->translatedFormat('M Y')),
        };
    }
}
