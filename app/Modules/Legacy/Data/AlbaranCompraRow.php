<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `ALBCOMPRACAB` — salida pura de
 * `App\Modules\Legacy\Extractors\AlbaranCompraExtractor::mapAlbaranRow()`, sin dependencia de DB.
 * `icgNumserie`/`icgNumalbaran`/`icgN` son la llave natural compuesta (mismo patrón que
 * `AlbaranRow`, `icgN` es texto — ver docblock de la migración).
 */
final readonly class AlbaranCompraRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumalbaran,
        public string $icgN,
        public ?int $icgCodproveedor,
        public ?DateTimeImmutable $fecha,
        public ?float $totalneto,
        public bool $facturado,
        public ?string $icgNumseriefac,
        public ?int $icgNumfac,
    ) {
    }
}
