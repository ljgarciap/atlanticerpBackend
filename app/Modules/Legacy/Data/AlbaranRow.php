<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `ALBVENTACAB` — salida pura de
 * `App\Modules\Legacy\Extractors\AlbaranExtractor::mapAlbaranRow()`, sin dependencia de DB.
 * `icgNumserie`/`icgNumalbaran`/`icgN` son la llave natural compuesta de ICG (PK real de
 * ALBVENTACAB — 3 columnas, mismo patrón que `PedidoRow`, sin VERSION).
 *
 * `icgN` es `string`, no `int` — confirmado contra ICG real (§13.2 del diseño): `N` es `nchar(1)`
 * de texto, valor constante `'B'`. A diferencia de `PedidoRow`/`CotizacionRow` (ya desplegadas con
 * el tipo equivocado antes de este hallazgo), esta DTO declara el tipo correcto desde el diseño
 * inicial.
 */
final readonly class AlbaranRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumalbaran,
        public string $icgN,
        public ?int $icgCodcliente,
        public ?DateTimeImmutable $fecha,
        public ?float $totalneto,
        public bool $facturado,
        public ?int $icgCodvendedor,
        public ?string $icgNumseriefac,
        public ?int $icgNumfac,
        // SCRUM-842 — de ALBVENTACAMPOSLIBRES (LEFT JOIN, ver AlbaranExtractor::albaranesQuery()).
        public ?string $observaciones = null,
    ) {
    }
}
