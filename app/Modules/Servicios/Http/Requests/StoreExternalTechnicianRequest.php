<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-264 RN1 — nombre/empresa/tarifa obligatorios, especialidad/telefono/email opcionales. */
class StoreExternalTechnicianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre'       => ['required', 'string', 'max:255'],
            'empresa'      => ['required', 'string', 'max:255'],
            'especialidad' => ['nullable', 'string', 'max:255'],
            'telefono'     => ['nullable', 'string', 'max:50'],
            'email'        => ['nullable', 'email', 'max:255'],
            'tarifa_dia'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
