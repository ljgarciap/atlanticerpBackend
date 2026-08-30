<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de `filas` de "Ventas por medio de pago" (4M4) — Batch 23 Grupo 3, SCRUM-665→669,
 * REQ-588→592. Los 9 nombres de columna son el vocabulario REAL de `AdminContPayment::METODOS`
 * (`deposito`, `ajuste_cuenta`, no `deposito_bancario`/`ajustes_cuenta`) — exactamente el mismatch
 * que Pre-QA encontró y corrigió en este mismo batch antes de que existiera este Resource.
 *
 * @property array{fecha: string, cliente: string, documento: string, base_imponible: float, itbms: float, total: float, transferencia: float, cheque: float, efectivo: float, tarjeta: float, deposito: float, yappy: float, link_pago: float, retencion_impuestos: float, ajuste_cuenta: float} $resource
 */
final class PaymentMethodSalesRowResource extends JsonResource
{
    /** @return array{fecha: string, cliente: string, documento: string, base_imponible: float, itbms: float, total: float, transferencia: float, cheque: float, efectivo: float, tarjeta: float, deposito: float, yappy: float, link_pago: float, retencion_impuestos: float, ajuste_cuenta: float} */
    public function toArray(Request $request): array
    {
        return [
            'fecha'               => $this->resource['fecha'],
            'cliente'             => $this->resource['cliente'],
            'documento'           => $this->resource['documento'],
            'base_imponible'      => $this->resource['base_imponible'],
            'itbms'               => $this->resource['itbms'],
            'total'               => $this->resource['total'],
            'transferencia'       => $this->resource['transferencia'],
            'cheque'              => $this->resource['cheque'],
            'efectivo'            => $this->resource['efectivo'],
            'tarjeta'             => $this->resource['tarjeta'],
            'deposito'            => $this->resource['deposito'],
            'yappy'               => $this->resource['yappy'],
            'link_pago'           => $this->resource['link_pago'],
            'retencion_impuestos' => $this->resource['retencion_impuestos'],
            'ajuste_cuenta'       => $this->resource['ajuste_cuenta'],
        ];
    }
}
