<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-163 (REQ-101) — reusado para store/update. `tipo` es texto libre por ahora (catálogo
 * cerrado pendiente de que el cliente defina la matriz real, ver docblock de ReglaAprobacion).
 */
class ReglaAprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo'            => ['required', 'string', 'max:255'],
            'activo'          => ['required', 'boolean'],
            'observaciones'   => ['nullable', 'string'],
            'aprobador_ids'   => ['required', 'array', 'min:1'],
            'aprobador_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
