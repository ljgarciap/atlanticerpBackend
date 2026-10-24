<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la vista de validación de pedidos gemelos de ICG (`PedidosGemelosReportService`).
 *
 * @property array{order_id: int, icg: string, order_number: string, cliente: string|null, ruc: string|null, dv: string|null, vendedor: string|null, fecha: string|null, hoja: string|null, etapa: string, total_icg: float, facturado_icg: float, pendiente: float, facturado_atlanticerp: float, saldo: float, advertencias: list<string>, facturable: bool, motivo_no_facturable: string|null} $resource
 */
final class PedidoGemeloRowResource extends JsonResource
{
    /** @return array{order_id: int, icg: string, order_number: string, cliente: string|null, ruc: string|null, dv: string|null, vendedor: string|null, fecha: string|null, hoja: string|null, etapa: string, total_icg: float, facturado_icg: float, pendiente: float, facturado_atlanticerp: float, saldo: float, advertencias: list<string>, facturable: bool, motivo_no_facturable: string|null} */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'order_id'             => $r['order_id'],
            'icg'                  => $r['icg'],
            'order_number'         => $r['order_number'],
            'cliente'              => $r['cliente'],
            'ruc'                  => $r['ruc'],
            'dv'                   => $r['dv'],
            'vendedor'             => $r['vendedor'],
            'fecha'                => $r['fecha'],
            'hoja'                 => $r['hoja'],
            'etapa'                => $r['etapa'],
            'total_icg'            => $r['total_icg'],
            'facturado_icg'        => $r['facturado_icg'],
            'pendiente'            => $r['pendiente'],
            'facturado_atlanticerp'      => $r['facturado_atlanticerp'],
            'saldo'                => $r['saldo'],
            'advertencias'         => $r['advertencias'],
            'facturable'           => $r['facturable'],
            'motivo_no_facturable' => $r['motivo_no_facturable'],
        ];
    }
}
