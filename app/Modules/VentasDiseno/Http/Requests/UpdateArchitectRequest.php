<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Shared\Catalogs\CatalogService;
use Illuminate\Validation\Rule;

// SCRUM-734 (sección 8, RN8.5) — edición in-place de un Arquitecto ya guardado,
// sin sub_client_id (no se reparenta a otro Subcliente desde acá).
class UpdateArchitectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'     => ['sometimes', Rule::in(CatalogService::keys('architect_type'))],
            'name'     => ['required', 'string', 'max:255'],
            'phone'    => ['nullable', 'required_without_all:email,document', 'string', 'max:50'],
            'email'    => ['nullable', 'required_without_all:phone,document', 'email', 'max:255'],
            'document' => ['nullable', 'required_without_all:phone,email', 'string', 'max:100'],
        ];
    }
}
