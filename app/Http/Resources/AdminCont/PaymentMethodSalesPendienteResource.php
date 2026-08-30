<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de `pendientes` de "Ventas por medio de pago" — Batch 23 Grupo 3, SCRUM-668. Campo
 * `documento` (no `factura`) — el nombre que Pre-QA encontró mal en el frontend antes de que
 * existiera este Resource.
 *
 * @property array{fecha: string, documento: string, cliente: string, proyecto: string|null, monto: float} $resource
 */
final class PaymentMethodSalesPendienteResource extends JsonResource
{
    /** @return array{fecha: string, documento: string, cliente: string, proyecto: string|null, monto: float} */
    public function toArray(Request $request): array
    {
        return [
            'fecha'     => $this->resource['fecha'],
            'documento' => $this->resource['documento'],
            'cliente'   => $this->resource['cliente'],
            'proyecto'  => $this->resource['proyecto'],
            'monto'     => $this->resource['monto'],
        ];
    }
}
