<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RN1 de REQ-530 — banco y últimos 4 dígitos obligatorios; tipo/moneda con default. */
class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'banco'             => ['required', 'string', 'max:255'],
            'ultimos_4_digitos' => ['required', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'tipo_cuenta'       => ['sometimes', Rule::in([
                AdminContBankAccount::TIPO_CORRIENTE,
                AdminContBankAccount::TIPO_AHORRO,
                AdminContBankAccount::TIPO_TARJETA_CREDITO,
            ])],
            'moneda' => ['sometimes', 'string', 'size:3'],
            // SCRUM-857 (REQ-458) — opcional; ver docblock de la migración/modelo para la excepción
            // a RN4 de REQ-530.
            'numero_cuenta_completo' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
