<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** SCRUM-1002 — RUC (`155704849-2-2021`) o cédula (`8-123-456`) a validar contra la DGI. */
class ValidateTaxIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tax_id' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[0-9A-Za-z\-\s]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tax_id.required' => 'Ingresa el RUC o la cédula a validar.',
            'tax_id.min'      => 'El RUC o cédula es demasiado corto.',
            'tax_id.max'      => 'El RUC o cédula no puede superar 100 caracteres.',
            'tax_id.regex'    => 'El RUC o cédula solo admite letras, números y guiones.',
        ];
    }
}
