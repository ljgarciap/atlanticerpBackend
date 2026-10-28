<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-292 RN1 — los 3 valores capturados + la bandera de licencia médica (RN4), un mes/año puntual.
 * Batch 10 (REQ-258 RN2) — `calidad_pct` es opcional: solo aplica a técnicos con
 * `has_bonus_plan=true` (hoy solo Tecnico Test), captura manual del % directamente (no hay
 * conversión de rango, a diferencia de los otros 3 valores crudos).
 */
class StoreCommissionCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'satisfaccion_promedio' => ['required', 'numeric', 'min:1', 'max:5'],
            'incidencias_puntualidad' => ['required', 'integer', 'min:0'],
            'calificacion_actitud' => ['required', 'numeric', 'min:1', 'max:5'],
            'licencia_medica' => ['sometimes', 'boolean'],
            'calidad_pct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
