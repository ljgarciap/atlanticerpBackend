<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado de `App\Modules\Legacy\Services\ProductoMapper` para UN artículo ya staged —
 * el shape resuelto hacia `ventas_diseno.catalog_products`, o el motivo por el que no se puede
 * mapear (`excludedReason !== null`).
 */
final readonly class MappedProducto
{
    public function __construct(
        public int $icgCodarticulo,
        public string $reference,
        public ?string $factoryReference,
        public string $description,
        public ?string $brand,
        public ?float $priceFull,
        public ?float $cost,
        public ?string $excludedReason = null,
        // SCRUM-921 — stock real de AL4 (legacy.productos.stock_al4), consumido por
        // CatalogProductLoader SOLO al crear el catalog_product (nunca en update). `null` =
        // artículo sin fila en AL4, distinto de 0 (stock real cero) — ver docblock de esa clase.
        public ?float $stockInicial = null,
    ) {
    }

    public function isExcluded(): bool
    {
        return $this->excludedReason !== null;
    }
}
