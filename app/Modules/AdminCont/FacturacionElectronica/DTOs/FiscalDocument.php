<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

use DateTimeImmutable;

/**
 * Documento canónico interno de AtlanticERP para certificar ante cualquier PAC — ni MSL ni Digifact
 * se referencian desde acá. Ver docs/architecture/facturacion-electronica-pac-diseno.md para el
 * diseño completo y el mapeo campo a campo contra cada esquema de proveedor.
 *
 * @param FiscalDocumentItem[] $items
 * @param FiscalPayment[] $pagos
 */
final readonly class FiscalDocument
{
    public function __construct(
        public string $tipoDocumento, // factura | nota_credito
        public string $serie,
        public string $numero,
        public DateTimeImmutable $fechaEmision,
        public FiscalParty $emisor,
        public FiscalParty $receptor,
        /** @var FiscalDocumentItem[] */
        public array $items,
        /** @var FiscalPayment[] */
        public array $pagos,
        public ?FiscalDocumentReference $referencia = null,
    ) {
    }
}
