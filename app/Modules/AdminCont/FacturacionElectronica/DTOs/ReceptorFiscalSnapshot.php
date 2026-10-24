<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\DTOs;

use App\Modules\AdminCont\Models\AdminContInvoice;
use DateTimeImmutable;

/**
 * SCRUM-1140 — receptor congelado de una factura (columnas `receptor_*` de `admin_contab.invoices`, ver la migración
 * `add_receptor_snapshot_to_admin_contab_invoices_table`). Es lo que de verdad se envía al PAC: `tipo` `01` o `02`, el
 * documento (sin RUC/DV en `02`) y la razón social validada por la DGI.
 *
 * `email`/`direccion` no se congelan: siguen saliendo del cliente al armar el `FiscalParty` (`toFiscalParty()`).
 */
final readonly class ReceptorFiscalSnapshot
{
    public const TIPO_CONTRIBUYENTE    = '01';
    public const TIPO_CONSUMIDOR_FINAL = '02';

    public function __construct(
        public string $tipo,
        public ?string $ruc,
        public ?string $dv,
        public string $nombre,
        public ?DateTimeImmutable $validadoAt = null,
    ) {
    }

    /** Contribuyente (`01`): documento, DV solo si la DGI lo trajo, y la razón social que envía el PAC. */
    public static function contribuyente(string $ruc, ?string $dv, string $nombre, ?DateTimeImmutable $validadoAt = null): self
    {
        $dv = $dv !== null ? trim($dv) : null;

        return new self(self::TIPO_CONTRIBUYENTE, trim($ruc), $dv === '' ? null : $dv, $nombre, $validadoAt);
    }

    /** Consumidor final (`02`): sin RUC, DV ni validación contra la DGI. */
    public static function consumidorFinal(string $nombre = 'CONSUMIDOR FINAL'): self
    {
        return new self(self::TIPO_CONSUMIDOR_FINAL, null, null, $nombre, null);
    }

    /** NULL cuando la factura no tiene receptor congelado (anterior al cambio): el builder lee el cliente en vivo. */
    public static function desdeFactura(AdminContInvoice $invoice): ?self
    {
        $tipo = $invoice->receptor_tipo;
        if ($tipo === null || $tipo === '') {
            return null;
        }

        $validadoAt = $invoice->receptor_validado_at;

        return new self(
            tipo: $tipo,
            ruc: $invoice->receptor_ruc,
            dv: $invoice->receptor_dv,
            nombre: (string) $invoice->receptor_nombre,
            validadoAt: $validadoAt !== null ? DateTimeImmutable::createFromInterface($validadoAt) : null,
        );
    }

    public function esConsumidorFinal(): bool
    {
        return $this->tipo === self::TIPO_CONSUMIDOR_FINAL;
    }

    /**
     * Atributos listos para `AdminContInvoice::create()` / `update()`.
     *
     * @return array<string, mixed>
     */
    public function comoAtributosDeFactura(): array
    {
        return [
            'receptor_tipo'        => $this->tipo,
            'receptor_ruc'         => $this->ruc,
            'receptor_dv'          => $this->dv,
            'receptor_nombre'      => $this->nombre,
            'receptor_validado_at' => $this->validadoAt,
        ];
    }

    public function toFiscalParty(?string $email = null, ?string $direccion = null): FiscalParty
    {
        if ($this->esConsumidorFinal()) {
            return FiscalParty::consumidorFinal($this->nombre);
        }

        return new FiscalParty(
            tipo: $this->tipo,
            ruc: $this->ruc,
            dv: $this->dv,
            nombre: $this->nombre,
            email: $email,
            direccion: $direccion,
        );
    }
}
