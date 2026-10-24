<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/users/reset-passwords — reset masivo de contraseñas (solo superadmin). */
class ResetPasswordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dry_run'       => ['sometimes', 'boolean'],
            'except_ids'    => ['sometimes', 'array'],
            'except_ids.*'  => ['integer', 'min:1'],
            'include_self'  => ['sometimes', 'boolean'],
            // H5: por defecto el masivo NO incluye superadmin; con true se incluyen (mismo criterio en dry_run y apply).
            'include_superadmins' => ['sometimes', 'boolean'],
            // Texto exacto `RESETEAR <N>`; se compara en el controlador contra el N que resuelve el servidor.
            'confirm'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'operator_password' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
