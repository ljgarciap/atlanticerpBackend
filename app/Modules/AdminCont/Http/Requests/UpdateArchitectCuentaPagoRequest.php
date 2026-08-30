<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-517 — `bank_account_id` nullable a propósito: permite "des-asignar" y volver a que el
 *  default resuelto (`defaultBankAccount()`) aplique otra vez. */
class UpdateArchitectCuentaPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_account_id' => ['nullable', 'integer'],
        ];
    }
}
