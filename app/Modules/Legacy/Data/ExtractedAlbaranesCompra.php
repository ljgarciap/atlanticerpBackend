<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de la extracción de Albaranes de Compra — mismo shape que
 * `ExtractedAlbaranes` (SCRUM-759), duplicado a propósito (no genérico sobre venta/compra) para
 * mantener cada entidad independiente y testeable por separado, mismo criterio ya establecido en
 * el módulo.
 */
final readonly class ExtractedAlbaranesCompra
{
    /**
     * @param list<AlbaranCompraRow>     $albaranes
     * @param list<AlbaranCompraItemRow> $items
     */
    public function __construct(
        public array $albaranes,
        public array $items,
    ) {
    }

    /** @return list<array{0: string, 1: int, 2: string}> */
    public function compositeKeys(): array
    {
        $seen = [];
        foreach ($this->albaranes as $albaran) {
            $key = self::compositeKeyString($albaran->icgNumserie, $albaran->icgNumalbaran, $albaran->icgN);

            $seen[$key] = [$albaran->icgNumserie, $albaran->icgNumalbaran, $albaran->icgN];
        }

        return array_values($seen);
    }

    public static function compositeKeyString(string $numserie, int $numalbaran, string $n): string
    {
        return "{$numserie}|{$numalbaran}|{$n}";
    }
}
