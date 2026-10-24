<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Contracts;

use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocument;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalDocumentResult;
use App\Modules\AdminCont\FacturacionElectronica\DTOs\FiscalPartyLookupResult;

/**
 * Puerto hacia cualquier Proveedor Autorizado Calificado (PAC). Facturación, Notas Crédito y
 * cualquier UI que muestre CUFE/QR hablan solo con esta interfaz — nunca con un HTTP client de
 * MSL o Digifact directamente. Mismo principio que App\Shared\Contracts\DomainEvent: contrato
 * interno estable hoy, implementación intercambiable después sin tocar la lógica de negocio.
 *
 * Ver docs/architecture/facturacion-electronica-pac-diseno.md para el diseño completo y qué
 * adapter implementa cada método concreto.
 */
interface PacProviderContract
{
    public function providerName(): string;

    public function certificarFactura(FiscalDocument $documento): FiscalDocumentResult;

    public function certificarNotaCredito(FiscalDocument $documento): FiscalDocumentResult;

    public function consultarEstado(string $serie, string $numero): FiscalDocumentResult;

    public function validarReceptor(string $rucOCedula): FiscalPartyLookupResult;
}
