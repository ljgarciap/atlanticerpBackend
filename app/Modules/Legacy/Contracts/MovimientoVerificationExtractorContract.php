<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1136 F2 — agregados del lado ICG de MOVIMENTS para `legacy:verify-movimientos` (solo lectura).
 */
interface MovimientoVerificationExtractorContract
{
    /**
     * Resumen de MOVIMENTS: total de filas, rango de `ID` y, por `TIPO` (con trim; vacío = ''), filas y
     * suma de UNIDADES.
     *
     * @return array{total: int, min_id: int|null, max_id: int|null, por_tipo: array<string, array{filas: int, unidades: float}>}
     */
    public function resumen(): array;
}
