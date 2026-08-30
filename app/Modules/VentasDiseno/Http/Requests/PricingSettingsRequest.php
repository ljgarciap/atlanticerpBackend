<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-033: porcentajes/divisores de las fórmulas de Tipo de Precio — todos
 * "sometimes" porque Gerencia/Admin puede ajustar solo uno de los 4 valores
 * por vez desde el panel de Configuración de Cotización. Los divisores nunca
 * pueden ser 0 (división por cero en effectiveUnitPrice).
 */
class PricingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'project_discount_percent'            => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'special_additional_discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'partner_divisor'                     => ['sometimes', 'numeric', 'gt:0', 'max:10'],
            'premium_divisor'                     => ['sometimes', 'numeric', 'gt:0', 'max:10'],
            'min_margin_percent'                  => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
