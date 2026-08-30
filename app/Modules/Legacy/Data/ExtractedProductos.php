<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * Resultado completo de `App\Modules\Legacy\Extractors\ProductoExtractor::extract()` — las 3
 * tablas origen (ARTICULOS/PRECIOSVENTA/PRECIOSCOMPRA), ya mapeadas a DTOs y filtradas.
 */
final readonly class ExtractedProductos
{
    /**
     * @param list<ProductoRow>       $productos
     * @param list<ProductoPrecioRow> $precios
     * @param list<ProductoCostoRow>  $costos
     */
    public function __construct(
        public array $productos,
        public array $precios,
        public array $costos,
    ) {
    }

    /** @return list<int> Códigos de artículo únicos de esta extracción (para scopear el Mapper a esta corrida, no a todo el historial de staging). */
    public function icgCodarticulos(): array
    {
        return array_values(array_unique(array_map(
            fn (ProductoRow $producto): int => $producto->icgCodarticulo,
            $this->productos,
        )));
    }
}
