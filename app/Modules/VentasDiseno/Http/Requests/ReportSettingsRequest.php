<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-075/076: umbral+meses de la alerta de "Top de vendedores" y probabilidades
 * de "Forecast" — todos "sometimes" porque Gerencia puede ajustar solo uno de
 * los 4 valores por vez desde el panel de Configuración.
 */
class ReportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'low_sales_threshold'          => ['sometimes', 'numeric', 'min:0'],
            'low_sales_months'             => ['sometimes', 'integer', 'min:1', 'max:24'],
            'quote_probability_percent'    => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'proposal_probability_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
