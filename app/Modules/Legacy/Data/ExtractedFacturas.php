<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\FacturaExtractor::extract()` — la única
 * tabla origen (FACTURASVENTA), ya mapeada a DTOs. Sin tabla de líneas (a diferencia de
 * `ExtractedPedidos`/`ExtractedAlbaranes`) — ver docblock de la migración de `legacy.facturas`.
 */
final readonly class ExtractedFacturas
{
    /** @param list<FacturaRow> $facturas */
    public function __construct(
        public array $facturas,
    ) {
    }

    /**
     * Llaves naturales compuestas únicas de esta extracción (para scopear el Matcher a esta
     * corrida, mismo criterio que `ExtractedAlbaranes::compositeKeys()`).
     *
     * @return list<array{0: string, 1: int, 2: string}>
     */
    public function compositeKeys(): array
    {
        $seen = [];
        foreach ($this->facturas as $factura) {
            $key = self::compositeKeyString($factura->icgNumserie, $factura->icgNumfactura, $factura->icgN);

            $seen[$key] = [$factura->icgNumserie, $factura->icgNumfactura, $factura->icgN];
        }

        return array_values($seen);
    }

    public static function compositeKeyString(string $numserie, int $numfactura, string $n): string
    {
        return "{$numserie}|{$numfactura}|{$n}";
    }
}
