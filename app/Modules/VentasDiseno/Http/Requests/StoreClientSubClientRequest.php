<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-067, modo "crear subcliente": Subcliente nuevo bajo un Cliente Master ya
 * existente (el master_client_id viene del segmento de ruta, no del body). A
 * diferencia de StoreSubClientRequest (usado por el flujo mínimo de vinculación
 * desde Pipeline, ver ClientController), acá los contactos son obligatorios — es la
 * pantalla completa de Clientes. Dispara REQ-068 igual que StoreClientRequest.
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
            'tax_id'                => ['required', 'string', 'max:100'],
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
        ];
    }
}
