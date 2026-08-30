<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `PRESUPUESTOSLIN` — salida pura de
 * `App\Modules\Legacy\Extractors\CotizacionExtractor::mapItemRow()`, sin dependencia de DB.
 *
 * `icgNumserie`/`icgNumpresupuesto`/`icgN`/`icgVersion` NO son una llave propia de la línea —
 * PRESUPUESTOSLIN no declara una (ver docblock de la migración de `legacy.cotizacion_items`), son
 * la llave natural compuesta de la CABECERA a la que pertenece esta línea (el FK real declarado
 * en ICG). `App\Modules\Legacy\Services\CotizacionStagingLoader` las usa para resolver
 * `legacy_cotizacion_id` (el `id` interno de `legacy.cotizaciones`) antes de insertar.
 */
final readonly class CotizacionItemRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumpresupuesto,
        public int $icgN,
        public int $icgVersion,
        public ?int $icgCodarticulo,
        public ?string $referencia,
        public ?string $descripcion,
        public ?float $cantidad,
        public ?float $precio,
        public ?float $descuento,
        public ?float $total,
        public ?float $costo,
    ) {
    }
}
