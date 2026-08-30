<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RN2 de REQ-534 — solo se puede asignar una cuenta activa. `bank_accounts` sin calificar: el
 * search_path del tenant ya resuelve el schema `admin_contab` (mismo gotcha documentado en
 * CLAUDE.md — calificar la tabla acá rompería `Rule::exists` fuera de un contexto de tenant
 * activo, sin necesidad real ya que el middleware de tenancy siempre corrió antes).
 */
class AssignBankMovementAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('activa', true),
            ],
        ];
    }
}
