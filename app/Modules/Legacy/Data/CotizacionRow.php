<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `PRESUPUESTOSCAB` — salida pura de
 * `App\Modules\Legacy\Extractors\CotizacionExtractor::mapCotizacionRow()`, sin dependencia de DB.
 * `icgNumserie`/`icgNumpresupuesto`/`icgN`/`icgVersion` son la llave natural compuesta de ICG
 * (PK real de PRESUPUESTOSCAB).
 */
final readonly class CotizacionRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumpresupuesto,
        public int $icgN,
        public int $icgVersion,
        public ?int $icgCodcliente,
        public ?DateTimeImmutable $fecha,
        public ?float $totalNeto,
        public ?float $totalBruto,
        public ?int $icgCodvendedor,
        public ?string $idestado,
        public ?string $supresupuesto,
        // SCRUM-842 — de PRESUPUESTOSCAMPOSLIBRES (LEFT JOIN, ver CotizacionExtractor::cotizacionesQuery()).
        public ?string $proyecto = null,
        public ?string $promocion = null,
    ) {
    }
}
