<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-625: umbral de margen bajó/mejoró de Pedidos, en puntos porcentuales. */
class OrdersSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'margin_drop_threshold_pts' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
