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
        // SCRUM-921 — stock real de STOCKS WHERE CODALMACEN='AL4' (Bodega Central, única fuente
        // de stock vendible según el negocio, ver v_msl_stock). `null` = el artículo no tiene
        // ninguna fila en AL4 (nunca tuvo movimiento ahí), distinto de 0 (stock real cero).
        public ?float $stockAl4 = null,
    ) {
    }
}
