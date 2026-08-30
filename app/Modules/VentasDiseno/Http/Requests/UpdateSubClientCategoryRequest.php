<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-064: cambiar la Categoría de un Subcliente desde el selector del modal de
 * detalle — `category: null` es válido (equivale a "Sin definir").
 */
class UpdateSubClientCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => [
                'nullable', 'string',
                Rule::in(['a_walkin', 'b_architect_designer', 'c_bidding', 'd_referred']),
            ],
        ];
    }
}
