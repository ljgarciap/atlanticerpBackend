<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-067, modo "crear subcliente": Subcliente nuevo bajo un Cliente Master ya
 * existente (el master_client_id viene del segmento de ruta, no del body). A
 * diferencia de StoreSubClientRequest (usado por el flujo mínimo de vinculación
 * desde Pipeline, ver ClientController), acá los contactos son obligatorios — es la
 * pantalla completa de Clientes. Tampoco crea proyecto ni Lead (SCRUM-1187, ver docblock de StoreClientRequest).
 */
class StoreClientSubClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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
