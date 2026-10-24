<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Contracts;

use App\Modules\Integrations\Lafayette\Data\ExtractedProductos;

/**
 * SCRUM-971 (épica SCRUM-969) — frontera entre el comando `lafayette:sync-productos` y el
 * mecanismo real de extracción, mismo patrón que `App\Modules\Legacy\Contracts\ProductoExtractorContract`
 * (SCRUM-750): permite a los Feature tests inyectar un fake (`$this->app->instance(...)`) sin
 * requerir la MariaDB externa real de Lafayette.
 */
interface ProductoExtractorContract
{
    public function extract(): ExtractedProductos;
}
