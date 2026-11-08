<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\CommissionTier;
use Illuminate\Foundation\Http\FormRequest;

/** Batch 14 (SCRUM-575, REQ-498) — CRUD de tramos, gateado por `primary_approver_only` en routes/admin-contab.php. */
class UpdateCommissionTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fase 28: un PUT parcial con solo `monto_maximo` fallaba (`gt:monto_minimo` comparaba contra un
     * campo ausente). Se completa con el mínimo vigente del tramo para validar contra él.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('monto_minimo')) {
            $actual = CommissionTier::find((int) $this->route('id'));
            if ($actual !== null) {
                $this->merge(['monto_minimo' => $actual->monto_minimo]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'monto_minimo' => ['sometimes', 'required', 'numeric', 'min:0'],
            'monto_maximo' => ['sometimes', 'nullable', 'numeric', 'gt:monto_minimo'],
            'porcentaje' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'orden' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }
}
