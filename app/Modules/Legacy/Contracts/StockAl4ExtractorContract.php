<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-1114 — frontera entre `legacy:sync-incremental-stock` y la lectura real de ICG, para que los
 * tests inyecten un fake sin SQL Server (mismo criterio que `ProductoExtractorContract`).
 */
interface StockAl4ExtractorContract
{
    /**
     * El stock actual de AL4 (Bodega Central) de ICG, por `CODARTICULO`. Un artículo sin fila en
     * AL4 simplemente no aparece en el mapa (se interpreta como 0 río abajo).
     *
     * @return array<int, float>
     */
    public function extract(): array;
}
