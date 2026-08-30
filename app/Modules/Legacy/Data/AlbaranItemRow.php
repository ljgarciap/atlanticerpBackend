<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

/**
 * DTO normalizado de una fila de `ALBVENTALIN` — salida pura de
 * `App\Modules\Legacy\Extractors\AlbaranExtractor::mapItemRow()`, sin dependencia de DB.
 *
 * `icgNumserie`/`icgNumalbaran`/`icgN` NO son una llave propia de la línea — ALBVENTALIN no
 * declara una (ver docblock de la migración de `legacy.albaran_items`), son la llave natural
 * compuesta de la CABECERA a la que pertenece esta línea (el FK real declarado en ICG).
 * `App\Modules\Legacy\Services\AlbaranStagingLoader` las usa para resolver `legacy_albaran_id` (el
 * `id` interno de `legacy.albaranes`) antes de insertar.
 */
final readonly class AlbaranItemRow
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
