<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\ExtractedProductos;

/**
 * Frontera entre el comando `legacy:sync-productos` y el mecanismo real de extracción — permite
 * a los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\ProductoExtractor` para la implementación
 * de producción.
 */
interface ProductoExtractorContract
{
    public function extract(): ExtractedProductos;
}
