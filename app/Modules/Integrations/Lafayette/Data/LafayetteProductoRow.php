<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Data;

/**
 * SCRUM-971 (épica SCRUM-969) — una fila cruda de `app_lafayette.vista_productos_lighting`, ya
 * castada a tipos PHP. Solo los campos que SÍ tienen mapeo cerrado (ver comentario de spec en
 * SCRUM-969, 2026-09-25) — el resto de columnas de la vista (`descripcion_ingles`,
 * `detalle_empaque`, `barra`, `uom`/`uom_instock`, `arancel_*`, `cubicaje`, `peso`, `preempaque`,
 * `cant_x_bulto`, `empaque`, `precio_1/2/3/4/7`, `empresa`) no se capturan acá — no se inventan
 * usos para datos sin mapeo definido.
 */
final readonly class LafayetteProductoRow
{
    public function __construct(
        public string $id,
        public ?string $referencia,
        public ?string $descripcion,
        public ?string $marca,
        public ?string $urlImagen,
        public ?string $categoria,
        public int $disponible,
        public ?float $precio5,
        public ?float $precio6,
        public ?string $cacheUpdatedAt,
    ) {
    }
}
