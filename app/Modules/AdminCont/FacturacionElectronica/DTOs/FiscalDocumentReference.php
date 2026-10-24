<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

use DateTimeImmutable;

/**
 * Referencia al documento original — solo para Notas de Crédito (Notas de Débito no están
 * modeladas en AtlanticERP todavía, ver docs/architecture/facturacion-electronica-pac-diseno.md).
 */
final readonly class FiscalDocumentReference
{
    public function __construct(
        public string $cufe,
        public DateTimeImmutable $fecha,
        public string $razon,
    ) {
    }
}
