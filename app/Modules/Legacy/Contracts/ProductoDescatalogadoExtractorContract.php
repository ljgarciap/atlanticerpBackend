<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\ExtractedProductos;

/**
 * SCRUM-1136 F5 — frontera del comando `legacy:sync-productos-descatalogados`: extrae SOLO los artículos descatalogados de ICG con stock distinto de cero en algún
 * almacén (con sus precios y costos). Contrato aparte de `ProductoExtractorContract` a propósito: así el flujo de activos (y su binding) no se toca.
 */
interface ProductoDescatalogadoExtractorContract
{
    public function extract(): ExtractedProductos;
}
