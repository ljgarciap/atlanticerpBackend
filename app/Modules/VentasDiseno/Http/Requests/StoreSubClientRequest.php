<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'master_client_id' => ['required', 'integer', Rule::exists('master_clients', 'id')],
            'business_name'    => ['required', 'string', 'max:255'],
            ...ReceptorTipo::reglas(), // SCRUM-1003 — tipo de cliente ante la DGI; RUC/DV obligatorios salvo Consumidor Final
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'category'         => [
                'nullable', 'string',
                Rule::in(['a_walkin', 'b_architect_designer', 'c_bidding', 'd_referred']),
            ],
            // SCRUM-1011 — `true` solo desde el modal de nuevo proyecto en Diseño: ahí no se
            // está creando un Lead, así que el Subcliente nace sin su tarjeta Lead automática.
            'from_design_context' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // SCRUM-1003 — el tipo de cliente ante la DGI y el RUC/DV condicionados, en español claro.
        return [
            ...ReceptorTipo::mensajes(),
            'tax_id_dv.regex' => 'El DV debe tener 1 o 2 dígitos.',
        ];
    }
}
