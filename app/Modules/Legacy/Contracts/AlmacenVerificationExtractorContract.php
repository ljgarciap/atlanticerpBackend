<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1136 F1 — agregados del lado ICG para `legacy:verify-almacenes` (solo lectura). Los conteos
 * salen de `COUNT(*)`, no de catálogos del sistema (`illumiread` no tiene VIEW DATABASE STATE).
 */
interface AlmacenVerificationExtractorContract
{
    /**
     * Filas y suma de STOCK por almacén de ICG (`CODALMACEN` con trim).
     *
     * @return array<string, array{filas: int, stock: float}>
     */
    public function stockPorAlmacen(): array;

    /**
     * Conteo de filas de ICG por entidad de staging: almacenes, costo_almacen, traspasos, inventarios.
     *
     * @return array<string, int>
     */
    public function conteos(): array;
}
