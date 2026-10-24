<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Data;

/**
 * SCRUM-971 (épica SCRUM-969) — resultado completo de
 * `App\Modules\Integrations\Lafayette\Extractors\ProductoExtractor::extract()`. A diferencia de
 * `App\Modules\Legacy\Data\ExtractedProductos` (3 tablas separadas: artículos/precios/costos),
 * acá es una sola vista ya desnormalizada — un solo `list<LafayetteProductoRow>`.
 */
final readonly class ExtractedProductos
{
    /** @param list<LafayetteProductoRow> $productos */
    public function __construct(
        public array $productos,
    ) {
    }
}
