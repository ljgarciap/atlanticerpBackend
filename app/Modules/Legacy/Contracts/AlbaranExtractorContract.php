<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\AlbaranItemRow;
use App\Modules\Legacy\Data\AlbaranRow;

/**
 * Frontera entre el comando `legacy:sync-albaranes` y el mecanismo real de extracción — permite a
 * los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\AlbaranExtractor` para la implementación de
 * producción. Mismo patrón que `PedidoExtractorContract`/`CotizacionExtractorContract`.
 *
 * Paginado por `extractItemsPage()` desde 2026-08-27 (mismo hallazgo real que
 * `CotizacionExtractorContract` el 2026-08-21, ver docblock de
 * `App\Modules\Legacy\Extractors\AlbaranExtractor`) — un `extract()` que devolviera las ~199,766
 * líneas de ALBVENTALIN de una sola vez materializaba todo en memoria PHP antes de poder stagear
 * nada, y el host de dev (EC2 t3.small, 2GB) no tiene margen para eso. Las cabeceras (~60,985
 * filas) sí se extraen completas de una — volumen mucho menor.
 */
interface AlbaranExtractorContract
{
    /** @return list<AlbaranRow> */
    public function extractAlbaranes(): array;

    /**
     * @return list<AlbaranItemRow> Página de hasta $limit filas. Lista vacía = no hay más.
     */
    public function extractItemsPage(int $offset, int $limit): array;
}
