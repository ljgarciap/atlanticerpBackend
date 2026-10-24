<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grupo 1d — REQ-019 (tipo "lead", sin cliente) / REQ-020 (tipo "design", requiere
 * Subcliente y crea directo en etapa Diseño).
 */
class StorePipelineCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'          => ['required', 'string', Rule::in(['lead', 'design'])],
            'name'          => ['required', 'string', 'max:255'],
            'tag'           => ['nullable', 'string', Rule::in(['design', 'quote', 'both'])],
            'observations'  => ['nullable', 'string'],
            'sub_client_id' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === 'design'),
                'nullable', 'integer', Rule::exists('sub_clients', 'id'),
            ],
            // SCRUM-849.4 — Gerencia puede asignar a uno o varios vendedores/diseñadores ya
            // desde la creación (gate de rol en el controller, no acá).
            'assignee_ids'   => ['sometimes', 'array', 'min:1'],
            'assignee_ids.*' => ['integer', Rule::exists('users', 'id')],
        ];
    }
}
