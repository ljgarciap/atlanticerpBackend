<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre'         => ['sometimes', 'string', 'max:255'],
            'tipo'           => ['sometimes', 'string', Rule::in(['diseno', 'cotizacion', 'ambos'])],
            'contacto'       => ['nullable', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:255'],
            'celular'        => ['nullable', 'string', 'max:50'],
            'encargado'      => ['nullable', 'string', 'max:255'],
            'developer'      => ['nullable', 'string', 'max:255'],
            'arquitecto'     => ['nullable', 'string', 'max:255'],
            'requires_technical_study'         => ['nullable', 'boolean'],
            'area'           => ['nullable', 'string', 'max:100'],
            'despacho'       => ['nullable', 'string', 'max:100'],
            'ubicacion'      => ['nullable', 'string', 'max:255'],
            'fecha_entrega'  => ['nullable', 'date'],
            'valor'          => ['nullable', 'numeric', 'min:0'],
            'notas'          => ['nullable', 'string'],
            'assignee_ids'   => ['nullable', 'array'],
            'assignee_ids.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }
}
