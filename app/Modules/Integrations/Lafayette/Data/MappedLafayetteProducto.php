<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Data;

use Carbon\Carbon;

/**
 * SCRUM-990 — resultado de `ProductoMapper` para UNA fila de Lafayette: el shape de `bodega.lafayette_productos`.
 * Reemplaza a `MappedProducto` (que apuntaba a `catalog_products`). Sin motivo de exclusión: el espejo guarda TODO lo
 * que la fuente trae, tal como viene ("no se deben inventar ni modificar valores provenientes de Lafayette").
 *
 * `precio5` = `precio_5` (decisión reversible de Luis, 2026-09-25). `precio6` = `precio_6`: SCRUM-1125 (2026-10-09) — el catálogo de Zona Libre
 * muestra `precio_6`; `precio_5` se sigue sincronizando para que volver atrás sea solo un cambio de columna.
 */
final readonly class MappedLafayetteProducto
{
    public function __construct(
        public string $lafayetteId,
        public ?string $referencia,
        public ?string $descripcion,
        public ?string $marca,
        public ?string $categoria,
        public ?string $urlImagen,
        public int $disponible,
        public ?float $precio5,
        public ?float $precio6,
        public ?Carbon $fuenteActualizadoAt,
    ) {
    }
}
