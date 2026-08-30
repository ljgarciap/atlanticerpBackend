<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\CotizacionExtractor::extract()` — las 2
 * tablas origen (PRESUPUESTOSCAB/PRESUPUESTOSLIN), ya mapeadas a DTOs.
 */
final readonly class ExtractedCotizaciones
{
    /**
     * @param list<CotizacionRow>     $cotizaciones
     * @param list<CotizacionItemRow> $items
     */
    public function __construct(
        public array $cotizaciones,
        public array $items,
    ) {
    }

    /**
     * Llaves naturales compuestas únicas de esta extracción (para scopear el Matcher a esta
     * corrida, mismo criterio que `ExtractedClientes::icgCodclientes()`).
     *
     * @return list<array{0: string, 1: int, 2: int, 3: int}>
     */
    public function compositeKeys(): array
    {
        $seen = [];
        foreach ($this->cotizaciones as $cotizacion) {
            $key = self::compositeKeyString(
                $cotizacion->icgNumserie,
                $cotizacion->icgNumpresupuesto,
                $cotizacion->icgN,
                $cotizacion->icgVersion,
            );

            $seen[$key] = [$cotizacion->icgNumserie, $cotizacion->icgNumpresupuesto, $cotizacion->icgN, $cotizacion->icgVersion];
        }

        return array_values($seen);
    }

    public static function compositeKeyString(string $numserie, int $numpresupuesto, int $n, int $version): string
    {
        return "{$numserie}|{$numpresupuesto}|{$n}|{$version}";
    }
}
