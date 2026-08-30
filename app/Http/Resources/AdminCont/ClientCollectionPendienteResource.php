<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de `pendientes` de "Reporte mensual por cliente"/"Acumulado" — Batch 23 Grupo 2,
 * SCRUM-654/659.
 *
 * @property array{fecha: string, factura: string, proyecto: string|null, monto: float} $resource
 */
final class ClientCollectionPendienteResource extends JsonResource
{
    /** @return array{fecha: string, factura: string, proyecto: string|null, monto: float} */
    public function toArray(Request $request): array
    {
        return [
            'fecha'    => $this->resource['fecha'],
            'factura'  => $this->resource['factura'],
            'proyecto' => $this->resource['proyecto'],
            'monto'    => $this->resource['monto'],
        ];
    }
}
