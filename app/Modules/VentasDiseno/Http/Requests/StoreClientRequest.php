<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-067, modo "crear cliente": Cliente Master nuevo + su primer Subcliente, en un
 * solo envío. Dispara REQ-068 (Lead automático en Pipeline) en el controller/service.
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
