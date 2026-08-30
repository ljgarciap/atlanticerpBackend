<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePipelineCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'string', 'max:255'],
            'tag'               => ['sometimes', 'nullable', 'string', Rule::in(['design', 'quote', 'both'])],
            'master_client_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('master_clients', 'id')],
            'sub_client_id'     => ['sometimes', 'nullable', 'integer', Rule::exists('sub_clients', 'id')],
            // max: mismo límite que la columna decimal(10,2) -- sin este tope, un valor que
            // desborda la columna llega intacto a Postgres y produce un 500 sin manejar en vez
            // de un 422 (SCRUM-81).
            'worked_area_m2'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'delivery_type'     => ['sometimes', 'nullable', 'string', Rule::in(['single', 'partial'])],
            'delivery_dates'    => ['sometimes', 'array'],
            'delivery_dates.*'  => ['date'],
            'observations'      => ['sometimes', 'nullable', 'string'],
        ];
    }
}
