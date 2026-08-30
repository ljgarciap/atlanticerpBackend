<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `TESORERIA` — salida pura de
 * `App\Modules\Legacy\Extractors\PagoExtractor::mapPagoRow()`, sin dependencia de DB.
 *
 * `icgOrigen`/`icgTipodocumento`/`icgSerie`/`icgNumero`/`icgN`/`icgPosicion` son la llave natural
 * compuesta REAL de ICG (6 columnas, §2 — ver docblock de la migración de `legacy.pagos` para el
 * porqué de las 6, no las 4 del sketch abreviado de §4).
 */
final readonly class PagoRow
{
    public function __construct(
        public string $icgOrigen,
        public string $icgTipodocumento,
        public string $icgSerie,
        public int $icgNumero,
        public string $icgN,
        public int $icgPosicion,
        public ?DateTimeImmutable $fechaDocumento,
        public ?DateTimeImmutable $fechaVencimiento,
        public ?float $importe,
        public ?int $icgCodformapago,
        public ?string $estado,
        public ?DateTimeImmutable $fechaSaldado,
    ) {
    }
}
