<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\ExtractedProveedores;

/**
 * Frontera entre el comando `legacy:sync-proveedores` y el mecanismo real de extracción — permite
 * a los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\ProveedorExtractor` para la implementación
 * de producción. Mismo patrón que `ProductoExtractorContract`/`ClienteExtractorContract`.
 */
interface ProveedorExtractorContract
{
    public function extract(): ExtractedProveedores;
}
