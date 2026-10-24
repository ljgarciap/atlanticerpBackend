<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\AlbaranCompraItemRow;
use App\Modules\Legacy\Data\AlbaranCompraRow;

/**
 * Frontera entre `legacy:sync-albaranes-compra` y el mecanismo real de extracción — permite a los
 * Feature tests inyectar un fake sin SQL Server real, mismo patrón que
 * `AlbaranExtractorContract`. Items paginados desde el diseño inicial (ALBCOMPRALIN es de volumen
 * comparable a ALBVENTALIN — el mismo host t3.small que ya mostró OOM con Albaranes de Venta sin
 * paginar no tiene margen distinto acá).
 */
interface AlbaranCompraExtractorContract
{
    /** @return list<AlbaranCompraRow> */
    public function extractAlbaranes(): array;

    /** @return list<AlbaranCompraItemRow> Página de hasta $limit filas. Lista vacía = no hay más. */
    public function extractItemsPage(int $offset, int $limit): array;
}
