<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Foundation\Http\FormRequest;

/** SCRUM-1002 — mismas reglas que el alta de SubCliente (`StoreClientSubClientRequest`). */
class UpdateSubClientTaxDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            ...ReceptorTipo::reglas(), // SCRUM-1003
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...ReceptorTipo::mensajes(),
            'business_name.required' => 'La razón social es obligatoria.',
            'business_name.max'      => 'La razón social no puede superar 255 caracteres.',
            'tax_id.max'             => 'El RUC o cédula no puede superar 100 caracteres.',
            'tax_id_dv.regex'        => 'El DV debe tener 1 o 2 dígitos.',
        ];
    }
}
