<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\ExtractedFacturas;

/**
 * Frontera entre el comando `legacy:sync-facturas` y el mecanismo real de extracción — permite a
 * los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\FacturaExtractor` para la implementación de
 * producción. Mismo patrón que `AlbaranExtractorContract`/`PedidoExtractorContract`.
 */
interface FacturaExtractorContract
{
    public function extract(): ExtractedFacturas;
}
