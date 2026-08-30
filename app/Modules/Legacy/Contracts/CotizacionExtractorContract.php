<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\CotizacionItemRow;
use App\Modules\Legacy\Data\CotizacionRow;

/**
 * Frontera entre el comando `legacy:sync-cotizaciones` y el mecanismo real de extracción — permite
 * a los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\CotizacionExtractor` para la implementación
 * de producción. Mismo patrón que `ClienteExtractorContract`/`ProductoExtractorContract`.
 *
 * Paginado por `extractItemsPage()` desde 2026-08-21 (hallazgo real, ver docblock de
 * `App\Modules\Legacy\Extractors\CotizacionExtractor`) — un `extract(): ExtractedCotizaciones` que
 * devolviera las 715,530 líneas de PRESUPUESTOSLIN de una sola vez materializaba todo en memoria
 * PHP antes de poder stagear nada, y el host de dev (EC2 t3.small, 2GB) no tiene margen para eso.
 * Las cabeceras (94,823 filas) sí se extraen completas de una — volumen mucho menor, ya validado.
 */
interface CotizacionExtractorContract
{
    /** @return list<CotizacionRow> */
    public function extractCotizaciones(): array;

    /**
     * @return list<CotizacionItemRow> Página de hasta $limit filas. Lista vacía = no hay más.
     */
    public function extractItemsPage(int $offset, int $limit): array;
}
