<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `ARTICULOS` — salida pura de
 * `App\Modules\Legacy\Extractors\ProductoExtractor::mapProductoRow()`, sin dependencia de DB.
 */
final readonly class ProductoRow
{
    public function __construct(
        public int $icgCodarticulo,
        public string $descripcion,
        public ?string $familiaNombre,
        public ?string $marcaNombre,
        public ?string $refProveedor,
        public bool $descatalogado,
    ) {
    }
}
