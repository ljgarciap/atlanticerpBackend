<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\ExtractedClientes;

/**
 * Frontera entre el comando `legacy:sync-clientes` y el mecanismo real de extracción — permite a
 * los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\ClienteExtractor` para la implementación de
 * producción. Mismo patrón que `ProductoExtractorContract` (SCRUM-750).
 */
interface ClienteExtractorContract
{
    public function extract(): ExtractedClientes;
}
