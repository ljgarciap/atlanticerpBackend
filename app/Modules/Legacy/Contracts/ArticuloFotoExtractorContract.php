<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Contracts;

/**
 * SCRUM-751 (seguimiento de SCRUM-750, épica SCRUM-749 — Migración ICG→AtlanticERP).
 * Illumination/migracion-icg-diseno.md §15.4. Frontera entre `Jobs\SyncProductoFotoJob`/
 * `Console\Commands\LegacySyncProductosFotosCommand` y el mecanismo real de extracción — permite
 * a los Feature tests inyectar una implementación fake sin requerir SQL Server real. Mismo patrón
 * que `ProductoExtractorContract` de SCRUM-750.
 */
interface ArticuloFotoExtractorContract
{
    /**
     * @return string|null Bytes crudos del BMP (`ARTICULOS.FOTO`), o `null` si el artículo no
     *                      existe, está descatalogado, o no tiene foto cargada.
     */
    public function extract(int $codArticulo): ?string;
}
