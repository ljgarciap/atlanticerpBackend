<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Fase 28 (grupo 4) — corregir la cuenta de un movimiento: cuenta activa y motivo obligatorio. */
class ReassignBankMovementAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('activa', true)],
            'motivo'          => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
