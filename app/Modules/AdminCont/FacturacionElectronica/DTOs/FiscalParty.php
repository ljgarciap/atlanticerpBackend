<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

/**
 * Emisor o receptor de un documento fiscal. `tipo` usa el código DGI (01 contribuyente ·
 * 02 consumidor final · 03 gobierno · 04 extranjero) — mismo vocabulario en MSL y en el
 * `TipoReceptor` del NUC de Digifact, ver docs/architecture/facturacion-electronica-pac-diseno.md.
 */
final readonly class FiscalParty
{
    public function __construct(
        public string $tipo,
        public ?string $ruc,
        public ?string $dv,
        public string $nombre,
        public ?string $email = null,
        public ?string $direccion = null,
        public string $pais = 'PA',
    ) {
    }

    public static function consumidorFinal(string $nombre = 'CONSUMIDOR FINAL'): self
    {
        return new self(tipo: '02', ruc: null, dv: null, nombre: $nombre);
    }
}
