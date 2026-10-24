<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services\Billing;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\VentasDiseno\Models\Quote;

/**
 * SCRUM-929 (B3b) — base imponible e ITBMS de UNA factura, para los reportes fiscales (Libro de Facturas,
 * Ventas por medio de pago). Fuente, en orden:
 *  1. las `invoice_lines` de la factura (Σ `subtotal` y Σ `itbms`) — es lo que REALMENTE se facturó: una
 *     factura `parcial` cubre solo una entrega, así que derivarlo de la cotización ENTERA sobrestimaba ×N
 *     (una cotización facturada en 3 partes aparecía 3 veces con la base y el ITBMS completos);
 *  2. si no tiene líneas (facturas legadas, anteriores a SCRUM-929) y tiene cotización: `Quote::netTotal()`/
 *     `Quote::itbms()`, igual que siempre;
 *  3. fallback sin cotización: división `monto / (1 + tasa)` (caso borde no esperado en datos reales).
 *
 * Espera `itbmsRate` y `lines` ya cargadas (los reportes las precargan en lote).
 */
final class InvoiceTaxBreakdown
{
    /** @return array{0: float, 1: float, 2: float} base imponible, ITBMS y porcentaje de la tasa de la factura */
    public static function of(AdminContInvoice $invoice, ?Quote $quote): array
    {
        $rate       = $invoice->itbmsRate;
        $porcentaje = $rate !== null ? $rate->porcentaje : 0.0;

        if ($invoice->lines->isNotEmpty()) {
            return [
                round((float) $invoice->lines->sum('subtotal'), 2),
                round((float) $invoice->lines->sum('itbms'), 2),
                $porcentaje,
            ];
        }

        if ($quote !== null) {
            return [round($quote->netTotal(), 2), round($quote->itbms(), 2), $porcentaje];
        }

        $base = round($invoice->monto / (1 + $porcentaje / 100), 2);

        return [$base, round($invoice->monto - $base, 2), $porcentaje];
    }
}
