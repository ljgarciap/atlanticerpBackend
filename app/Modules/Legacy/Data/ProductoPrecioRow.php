<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `PRECIOSVENTA` ya filtrada/mapeada — salida pura de
 * `App\Modules\Legacy\Extractors\ProductoExtractor::mapPrecioRow()`. Solo existe para las 4
 * tarifas vigentes (ver `App\Modules\Legacy\Support\PriceTypeMap`) — filas de tarifas no
 * mapeadas o marcadas `DESCATALOGADO=1` nunca llegan a instanciar este DTO (`mapPrecioRow()`
 * devuelve `null` en esos casos).
 */
final readonly class ProductoPrecioRow
{
    public function __construct(
        public int $icgCodarticulo,
        public int $idtarifav,
        public float $precioNeto,
        public ?float $precioBruto,
        public ?float $descuento,
        public bool $descatalogadoFila,
    ) {
    }
}
