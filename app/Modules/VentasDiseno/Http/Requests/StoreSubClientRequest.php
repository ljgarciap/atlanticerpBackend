<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

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
            'tax_id'           => ['required', 'string', 'max:100'],
            'delivery_address' => ['nullable', 'string', 'max:255'],
            'category'         => [
                'nullable', 'string',
                Rule::in(['a_walkin', 'b_architect_designer', 'c_bidding', 'd_referred']),
            ],
        ];
    }
}
