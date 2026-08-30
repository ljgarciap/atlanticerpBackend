<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `PEDVENTACAB` — salida pura de
 * `App\Modules\Legacy\Extractors\PedidoExtractor::mapPedidoRow()`, sin dependencia de DB.
 * `icgNumserie`/`icgNumpedido`/`icgN` son la llave natural compuesta de ICG (PK real de
 * PEDVENTACAB — 3 columnas, a diferencia de `CotizacionRow`, que tiene 4 por incluir VERSION).
 */
final readonly class PedidoRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumpedido,
        public int $icgN,
        public ?int $icgCodcliente,
        public ?DateTimeImmutable $fechapedido,
        public ?DateTimeImmutable $fechaentrega,
        public ?float $totneto,
        public ?int $icgCodvendedor,
        public ?string $idestado,
        public ?string $icgSeriealbaran,
        public ?int $icgNumeroalbaran,
    ) {
    }
}
