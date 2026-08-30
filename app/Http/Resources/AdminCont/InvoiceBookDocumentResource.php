<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de `documentos` del Libro de Facturas (4M3) — Batch 23 Grupo 3, SCRUM-661→664, REQ-584→587.
 *
 * @property array{fecha: string, ruc: string|null, cliente: string, tipo: string, motivo: string|null, documento: string, base_imponible: float, porcentaje: float, itbms: float, total: float} $resource
 */
final class InvoiceBookDocumentResource extends JsonResource
{
    /** @return array{fecha: string, ruc: string|null, cliente: string, tipo: string, motivo: string|null, documento: string, base_imponible: float, porcentaje: float, itbms: float, total: float} */
    public function toArray(Request $request): array
    {
        return [
            'fecha'          => $this->resource['fecha'],
            'ruc'            => $this->resource['ruc'],
            'cliente'        => $this->resource['cliente'],
            'tipo'           => $this->resource['tipo'],
            'motivo'         => $this->resource['motivo'],
            'documento'      => $this->resource['documento'],
            'base_imponible' => $this->resource['base_imponible'],
            'porcentaje'     => $this->resource['porcentaje'],
            'itbms'          => $this->resource['itbms'],
            'total'          => $this->resource['total'],
        ];
    }
}
