<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `ALBCOMPRALIN` — salida pura de
 * `App\Modules\Legacy\Extractors\AlbaranCompraExtractor::mapItemRow()`. Las 3 columnas de llave
 * son las de la CABECERA a la que pertenece la línea (ALBCOMPRALIN no declara llave propia, mismo
 * criterio que `AlbaranItemRow`).
 */
final readonly class AlbaranCompraItemRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumalbaran,
        public string $icgN,
        public ?int $icgCodarticulo,
        public ?string $referencia,
        public ?string $descripcion,
        public ?float $cantidad,
        public ?float $precio,
        public ?float $total,
    ) {
    }
}
