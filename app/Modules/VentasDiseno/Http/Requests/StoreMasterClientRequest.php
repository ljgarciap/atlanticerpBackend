<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'default_price_type'  => [
                'nullable', 'string',
                Rule::in(['public', 'project', 'special', 'partner', 'premium']),
            ],
        ];
    }
}
