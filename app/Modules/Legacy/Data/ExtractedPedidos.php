<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\PedidoExtractor::extract()` — las 2 tablas
 * origen (PEDVENTACAB/PEDVENTALIN), ya mapeadas a DTOs.
 */
final readonly class ExtractedPedidos
{
    /**
     * @param list<PedidoRow>     $pedidos
     * @param list<PedidoItemRow> $items
     */
    public function __construct(
        public array $pedidos,
        public array $items,
    ) {
    }

    /**
     * Llaves naturales compuestas únicas de esta extracción (para scopear el Matcher a esta
     * corrida, mismo criterio que `ExtractedCotizaciones::compositeKeys()`).
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public function compositeKeys(): array
    {
        $seen = [];
        foreach ($this->pedidos as $pedido) {
            $key = self::compositeKeyString($pedido->icgNumserie, $pedido->icgNumpedido, $pedido->icgN);

            $seen[$key] = [$pedido->icgNumserie, $pedido->icgNumpedido, $pedido->icgN];
        }

        return array_values($seen);
    }

    public static function compositeKeyString(string $numserie, int $numpedido, int $n): string
    {
        return "{$numserie}|{$numpedido}|{$n}";
    }
}
