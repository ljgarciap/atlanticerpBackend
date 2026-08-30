<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 14 (SCRUM-575, REQ-498) — CRUD de tramos, gateado por `mark_only` en routes/admin-contab.php. */
class UpdateCommissionTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'monto_minimo' => ['sometimes', 'required', 'numeric', 'min:0'],
            'monto_maximo' => ['sometimes', 'nullable', 'numeric', 'gt:monto_minimo'],
            'porcentaje'   => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'orden'        => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
