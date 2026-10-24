<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\FacturacionElectronica\Services;

use App\Modules\AdminCont\FacturacionElectronica\Exceptions\UnmappableItbmsRateException;

/**
 * Traduce un porcentaje de `admin_contab.itbms_rates` al código DGI de tasa ITBMS — el único
 * tramo del esquema fiscal confirmado IDÉNTICO entre MSL y el NUC de Digifact (00 · 01 · 02 · 03
 * = 0% · 7% · 10% · 15%, ver docs/architecture/facturacion-electronica-pac-diseno.md). Por eso
 * vive acá, compartido, y no dentro de cada adapter — a diferencia del mapeo de forma de pago,
 * que sí difiere por proveedor.
 *
 * "3.5% — Retención de impuesto" (`admin_contab.itbms_rates`, 50% del 7%) NO se mapea — guarda de la opción A
 * (Luis, 2026-10-05, reemplaza la interpretación del 2026-09-20 que la mapeaba a `01`): ni MSL ni el NUC de
 * Digifact tienen código de retención, y la prueba en sandbox (2026-10-05) mostró que MSL certifica la línea
 * como ITBMS 7% (total 32.10) sin validar que `pagos[]` coincida (31.05), dejando un documento fiscal
 * inmutable que no cuadra con la factura de AtlanticERP. Se lanza `UnmappableItbmsRateException` (el documento queda
 * en `error_configuracion`, la factura en AtlanticERP no se bloquea) hasta que un contador o MSL/DGI confirme cómo
 * reportarla; ese criterio decidirá si pasa a una columna `codigo_dgi` en `itbms_rates`.
 */
final class ItbmsCodeMapper
{
    private const string TASA_RETENCION = '3.50';

    private const array PORCENTAJE_A_CODIGO = [
        '0.00' => '00',
        '7.00' => '01',
        '10.00' => '02',
        '15.00' => '03',
    ];

    public function map(float $porcentaje): string
    {
        $clave = number_format($porcentaje, 2, '.', '');

        if ($clave === self::TASA_RETENCION) {
            throw UnmappableItbmsRateException::retencionPendienteDeCriterioFiscal($porcentaje);
        }

        $codigo = self::PORCENTAJE_A_CODIGO[$clave] ?? null;

        if ($codigo === null) {
            throw new UnmappableItbmsRateException($porcentaje);
        }

        return $codigo;
    }
}
