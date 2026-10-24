<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-067, modo "crear cliente": Cliente Master nuevo + su primer Subcliente, en un
 * solo envío. NO crea proyecto ni Lead en el Pipeline (SCRUM-1187 reemplazó REQ-068): el proyecto y la
 * tarjeta nacen con la cotización o el Lead del vendedor. `from_quote_context` (SCRUM-873) se sigue
 * aceptando por compatibilidad con el frontend, pero ya no cambia nada.
 */
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'max:255'],
            'default_price_type'    => [
                'nullable', 'string',
                Rule::in(['public', 'project', 'special', 'partner', 'premium']),
            ],
            'business_name'         => ['required', 'string', 'max:255'],
            ...ReceptorTipo::reglas(), // SCRUM-1003 — tipo de cliente ante la DGI; RUC/DV obligatorios salvo Consumidor Final
            'delivery_address'      => ['nullable', 'string', 'max:255'],
            'category'              => [
                'nullable', 'string',
                Rule::in(['a_walkin', 'b_architect_designer', 'c_bidding', 'd_referred']),
            ],
            'contacts'              => ['required', 'array', 'min:1'],
            'contacts.*.name'       => ['required', 'string', 'max:255'],
            'contacts.*.role'       => ['required', 'string', Rule::in(['client', 'architect', 'other'])],
            'contacts.*.phone'      => ['nullable', 'required_without:contacts.*.email', 'string', 'max:50'],
            'contacts.*.email'      => ['nullable', 'required_without:contacts.*.phone', 'email', 'max:255'],
            'from_quote_context'    => ['sometimes', 'boolean'],
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
