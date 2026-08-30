<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-066: nombre + rol obligatorios; al menos uno de teléfono/correo obligatorio.
 * Reusado para store y update (mismo patrón que StorePipelineCardContactRequest).
 *
 * Validación de formato agregada 2026-07-16 (hallazgo Marly, SCRUM-96): antes
 * solo se rechazaba vacío/espacios, así que un nombre puramente numérico o un
 * teléfono con letras se guardaban sin aviso. `name` no puede ser solo
 * dígitos; `phone` usa el mismo patrón que `UpdateProfileRequest` (dígitos,
 * espacios, guiones, `+` opcional).
 */
class StoreSubClientContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255', 'not_regex:/^\d+$/'],
            'role'  => ['required', 'string', Rule::in(['client', 'architect', 'other'])],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:50', 'regex:/^\+?[0-9\s-]{7,20}$/'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
        ];
    }
}
