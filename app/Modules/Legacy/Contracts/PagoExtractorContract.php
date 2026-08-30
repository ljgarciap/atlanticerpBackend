<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\PagoRow;

/**
 * Frontera entre el comando `legacy:sync-pagos` y el mecanismo real de extracción — permite a los
 * Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir SQL
 * Server real, ver `App\Modules\Legacy\Extractors\PagoExtractor` para la implementación de
 * producción.
 *
 * Paginado por `extractPage()` desde 2026-08-27 (hallazgo real: 2 OOM kills confirmados por
 * `dmesg -T` en dev.atlanticerp.ai corriendo `legacy:sync-pagos --dry-run` contra las 145,250 filas
 * reales de TESORERIA — mismo síntoma que Cotizaciones/Albaranes, pese a que Pagos es tabla única
 * sin líneas: el volumen por sí solo ya alcanza el límite del host t3.small. Ver docblock de
 * `App\Modules\Legacy\Extractors\PagoExtractor`). A diferencia de
 * `AlbaranExtractorContract`/`CotizacionExtractorContract` no hay una cabecera separada que
 * extraer completa — TODA la extracción es paginada, no solo unas líneas.
 */
interface PagoExtractorContract
{
    /**
     * @return list<PagoRow> Página de hasta $limit filas. Lista vacía = no hay más.
     */
    public function extractPage(int $offset, int $limit): array;
}
