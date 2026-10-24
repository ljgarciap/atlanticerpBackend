<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Exceptions;

use RuntimeException;

final class UnmappableItbmsRateException extends RuntimeException
{
    public function __construct(float $porcentaje, ?string $mensaje = null)
    {
        parent::__construct($mensaje ?? (
            "No hay código DGI de ITBMS mapeado para {$porcentaje}% — ver ItbmsCodeMapper y ".
            'docs/architecture/facturacion-electronica-pac-diseno.md ("Abierto").'
        ));
    }

    /**
     * Guarda de la tasa "3.5% — Retención de impuesto" (opción A, Luis 2026-10-05): MSL la reportaría a la DGI
     * como ITBMS 7% (ni MSL ni el NUC tienen código de retención y MSL recalcula el total sin validar `pagos[]`,
     * comprobado en sandbox), así que el total fiscal (p. ej. 32.10) no coincidiría con el de AtlanticERP (31.05). El
     * documento fiscal es inmutable ante la DGI, por eso no se certifica hasta confirmar el criterio con un contador.
     * El mensaje llega al usuario vía `fe_detalle` (Configuración Fiscal).
     */
    public static function retencionPendienteDeCriterioFiscal(float $porcentaje): self
    {
        return new self($porcentaje, 'No se certificó: la tasa «3.5% — Retención de impuesto» aún no se puede reportar a la DGI '
            .'(MSL la declararía al 7% y el total fiscal no coincidiría con el de la factura en AtlanticERP). '
            .'Pendiente de confirmación fiscal. Usa la tasa 7% o contacta a Administración.');
    }
}
