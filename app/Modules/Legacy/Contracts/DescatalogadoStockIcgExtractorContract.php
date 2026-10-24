<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1136 F5 (RF5) — agregados del lado ICG para `legacy:verify-descatalogados` (solo lectura): cuántos artículos descatalogados tienen stock <> 0 y la suma de ese stock por
 * almacén. Contrato aparte para que los tests inyecten un fake sin SQL Server.
 */
interface DescatalogadoStockIcgExtractorContract
{
    /** @return array{articulos: int, por_almacen: array<string, array{articulos: int, stock: float}>} */
    public function resumen(): array;
}
