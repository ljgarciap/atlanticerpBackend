<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\AlbaranExtractor::extract()` — las 2 tablas
 * origen (ALBVENTACAB/ALBVENTALIN), ya mapeadas a DTOs.
 */
final readonly class ExtractedAlbaranes
{
    /**
     * @param list<AlbaranRow>     $albaranes
     * @param list<AlbaranItemRow> $items
     */
    public function __construct(
        public array $albaranes,
        public array $items,
    ) {
    }

    /**
     * Llaves naturales compuestas únicas de esta extracción (para scopear el Matcher a esta
     * corrida, mismo criterio que `ExtractedPedidos::compositeKeys()`).
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
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
