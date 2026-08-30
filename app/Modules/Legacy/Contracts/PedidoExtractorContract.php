<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

use App\Modules\Legacy\Data\PedidoItemRow;
use App\Modules\Legacy\Data\PedidoRow;

/**
 * Frontera entre el comando `legacy:sync-pedidos` y el mecanismo real de extracción — permite a
 * los Feature tests inyectar una implementación fake (`$this->app->instance(...)`) sin requerir
 * SQL Server real, ver `App\Modules\Legacy\Extractors\PedidoExtractor` para la implementación de
 * producción. Mismo patrón que `CotizacionExtractorContract`/`ClienteExtractorContract`.
 *
 * Paginado por `extractItemsPage()` desde 2026-08-21 (mismo fix preventivo aplicado a
 * `CotizacionExtractorContract` tras el OOM real de SCRUM-757 — ver su docblock para el detalle
 * completo; PEDVENTALIN son 173,764 filas, ~24% del volumen de PRESUPUESTOSLIN que causó el OOM,
 * pero el host sigue igual de ajustado de memoria, así que se aplica el mismo patrón preventivo en
 * vez de esperar a que vuelva a fallar).
 */
interface PedidoExtractorContract
{
    /** @return list<PedidoRow> */
    public function extractPedidos(): array;

    /**
     * @return list<PedidoItemRow> Página de hasta $limit filas. Lista vacía = no hay más.
     */
    public function extractItemsPage(int $offset, int $limit): array;
}
