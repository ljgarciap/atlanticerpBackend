<?php

declare(strict_types=1);

namespace App\Http\Resources\AdminCont;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la tabla `tiers` de `GET /admin-contab/reports/commission-tiers` (Batch 22, SCRUM-643→647,
 * REQ-567). Envolver el array asociativo en un Resource — no un array crudo — es lo que permite que
 * Scramble (generador de OpenAPI/tipos TS) resuelva el shape completo de la lista incluyendo tipos
 * precisos; ver `docs/architecture/typescript-types-from-backend.md`. Usar SIEMPRE
 * `CarteraCommissionTierResource::collection($rows)` embebido como VALOR dentro de un array más
 * grande (nunca como el retorno completo del controller/ruta) — así se evita el envoltorio
 * `{"data": [...]}` que Laravel agrega por default cuando un Resource/colección es la respuesta
 * completa, manteniendo el shape JSON idéntico al que ya consume el frontend.
 *
 * @property array{monto_minimo: float, monto_maximo: float|null, porcentaje: float, orden: int, es_actual: bool} $resource
 */
final class CarteraCommissionTierResource extends JsonResource
{
    /** @return array{monto_minimo: float, monto_maximo: float|null, porcentaje: float, orden: int, es_actual: bool} */
    public function toArray(Request $request): array
    {
        return [
            'monto_minimo' => $this->resource['monto_minimo'],
            'monto_maximo' => $this->resource['monto_maximo'],
            'porcentaje'   => $this->resource['porcentaje'],
            'orden'        => $this->resource['orden'],
            'es_actual'    => $this->resource['es_actual'],
        ];
    }
}
