<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RN1 de REQ-564 — área, correo y teléfono son los 3 campos del contacto, todos obligatorios al crear. */
class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'area'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'telefono' => ['required', 'string', 'max:50'],
        ];
    }
}
