<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la tabla `filas` de "Reporte mensual por cliente" (4M1, agrupación 'dia') y "Mensual por
 * cliente — acumulado" (4M2, agrupación 'mes') — Batch 23 Grupo 2, SCRUM-651→660, REQ-574→583. Los
 * 3 campos de fecha son siempre los 3, con `null` en los que no aplican a la agrupación pedida
 * (nunca se omiten condicionalmente) — la UI ya decide qué columnas mostrar por la prop
 * `agrupacion`, no por presencia/ausencia de clave.
 *
 * @property array{fecha: string|null, anio: int|null, mes: string|null, num: int, importe: float, media: float, transferencia: float, cheque: float, efectivo: float, yappy: float, otros: float, total_caja: float} $resource
 */
final class ClientCollectionRowResource extends JsonResource
{
    /** @return array{fecha: string|null, anio: int|null, mes: string|null, num: int, importe: float, media: float, transferencia: float, cheque: float, efectivo: float, yappy: float, otros: float, total_caja: float} */
    public function toArray(Request $request): array
    {
        return [
            'fecha'         => $this->resource['fecha'] ?? null,
            'anio'          => $this->resource['anio'] ?? null,
            'mes'           => $this->resource['mes'] ?? null,
            'num'           => $this->resource['num'],
            'importe'       => $this->resource['importe'],
            'media'         => $this->resource['media'],
            'transferencia' => $this->resource['transferencia'],
            'cheque'        => $this->resource['cheque'],
            'efectivo'      => $this->resource['efectivo'],
            'yappy'         => $this->resource['yappy'],
            'otros'         => $this->resource['otros'],
            'total_caja'    => $this->resource['total_caja'],
        ];
    }
}
