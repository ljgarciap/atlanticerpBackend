<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use App\Shared\Catalogs\CatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** REQ-259 RN1 — nombre y especialidad obligatorios, teléfono/correo opcionales. */
class StoreInternalTechnicianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'especialidad' => ['required', 'string', Rule::in(CatalogService::keys('technician_specialty'))],
        ];
    }
}
