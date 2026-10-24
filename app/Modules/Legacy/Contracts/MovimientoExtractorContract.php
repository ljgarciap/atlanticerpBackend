<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1136 F2 — frontera del extractor de MOVIMENTS de ICG: paginación por LLAVE (keyset) sobre `ID`,
 * no por OFFSET (con 1,38 M de filas el OFFSET profundo es cuadrático). Permite a los tests inyectar un fake
 * sin SQL Server.
 */
interface MovimientoExtractorContract
{
    /**
     * Hasta $limit filas con `ID` > $afterId, ordenadas por `ID` ascendente, YA mapeadas a columnas de staging
     * (snake_case, `icg_id` = `ID`). Lista vacía = no hay más.
     *
     * @return list<array<string, mixed>>
     */
    public function extractAfter(int $afterId, int $limit): array;
}
