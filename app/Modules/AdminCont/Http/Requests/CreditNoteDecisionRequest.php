<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Batch 13 (REQ-494) — decisión de Mark sobre una nota pendiente. RN4/RN5: `motivo_rechazo` es
 * obligatorio solo al rechazar, nunca al aprobar. El gate de identidad (solo Mark) es el
 * middleware `primary_approver_only` sobre la ruta, no algo que valide este Request.
 */
class CreditNoteDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate real: middleware `primary_approver_only` sobre la ruta, ver routes/admin-contab.php
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approve' => ['required', 'boolean'],
            'motivo_rechazo' => [
                Rule::requiredIf($this->boolean('approve') === false),
                'nullable', 'string', 'max:1000',
            ],
        ];
    }
}
