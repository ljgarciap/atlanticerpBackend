<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1136 F1 — frontera común de los extractores paginados de almacenes/stock/costos/traspasos/
 * inventarios de ICG: permite a los tests inyectar un fake sin SQL Server (mismo criterio que
 * `PagoExtractorContract`). Cada tabla tiene su propia interfaz hija para poder bindearla por separado.
 */
interface IcgPaginatedExtractorContract
{
    /**
     * Página de hasta $limit filas YA mapeadas a columnas de staging (snake_case).
     * Lista vacía = no hay más.
     *
     * @return list<array<string, mixed>>
     */
    public function extractPage(int $offset, int $limit): array;
}
