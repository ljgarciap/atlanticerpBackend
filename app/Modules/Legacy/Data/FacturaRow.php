<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Data;

use DateTimeImmutable;

/**
 * DTO normalizado de una fila de `FACTURASVENTA` — salida pura de
 * `App\Modules\Legacy\Extractors\FacturaExtractor::mapFacturaRow()`, sin dependencia de DB.
 * `icgNumserie`/`icgNumfactura`/`icgN` son la llave natural compuesta de ICG (PK real de
 * FACTURASVENTA — 3 columnas, mismo patrón que `AlbaranRow`/`PedidoRow`).
 *
 * `icgN` es `string`, no `int` — ver GOTCHA en el docblock de la migración de `legacy.facturas`
 * (no confirmado directamente para FACTURASVENTA, declarado texto por el mismo patrón fuerte de
 * las 6 tablas hermanas ya auditadas).
 */
final readonly class FacturaRow
{
    public function __construct(
        public string $icgNumserie,
        public int $icgNumfactura,
        public string $icgN,
        public ?int $icgCodcliente,
        public ?int $icgCodvendedor,
        public ?DateTimeImmutable $fecha,
        public ?float $totalNeto,
        public ?float $totalImpuestos,
        public ?float $totalBruto,
        public ?string $sufactura,
        // SCRUM-841 — Facturación Electrónica, de FACTURASVENTACAMPOSLIBRES (LEFT JOIN, ver
        // FacturaExtractor::facturasQuery()). Todo nullable — no toda factura tiene esta fila.
        public ?string $feQr = null,
        public ?string $feAuthNumber = null,
        public ?string $feSerie = null,
        public ?string $feNumero = null,
        public ?string $feFechaEmision = null,
        public ?string $feFechaRecepcion = null,
        public ?string $feError = null,
        public ?string $feEstado = null,
    ) {
    }
}
