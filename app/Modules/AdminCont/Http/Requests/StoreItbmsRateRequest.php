<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RN3 de REQ-558: porcentaje y descripción son obligatorios al crear una tasa personalizada. */
class StoreItbmsRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'descripcion' => ['required', 'string', 'max:1000'],
            'porcentaje'  => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
