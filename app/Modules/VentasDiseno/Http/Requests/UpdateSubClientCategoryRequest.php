<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Shared\Catalogs\CatalogService;
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
                Rule::in(CatalogService::keys('client_segment')),
            ],
        ];
    }
}
