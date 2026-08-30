<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `PRECIOSCOMPRA` — salida pura de
 * `App\Modules\Legacy\Extractors\ProductoExtractor::mapCostoRow()`. Sin filtrar: se captura toda
 * fila costo/proveedor tal cual viene de ICG (la selección del proveedor por defecto ocurre en
 * `App\Modules\Legacy\Services\ProductoMapper`, no acá).
 */
final readonly class ProductoCostoRow
{
    public function __construct(
        public int $icgCodarticulo,
        public int $codproveedor,
        public float $costoNeto,
    ) {
    }
}
