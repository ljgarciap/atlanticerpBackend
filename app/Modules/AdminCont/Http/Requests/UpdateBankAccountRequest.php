<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCRUM-857 (REQ-458) — antes no existía ningún endpoint para editar una cuenta bancaria ya
 * creada (solo activar/desactivar), así que no había forma de completar `numero_cuenta_completo`
 * en una cuenta existente sin borrarla y recrearla. Todos los campos son `sometimes` — un PUT
 * parcial (ej. solo `numero_cuenta_completo`) no debe pisar el resto con valores vacíos.
 */
class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'banco'             => ['sometimes', 'string', 'max:255'],
            'ultimos_4_digitos' => ['sometimes', 'string', 'size:4', 'regex:/^\d{4}$/'],
            'tipo_cuenta'       => ['sometimes', Rule::in([
                AdminContBankAccount::TIPO_CORRIENTE,
                AdminContBankAccount::TIPO_AHORRO,
                AdminContBankAccount::TIPO_TARJETA_CREDITO,
            ])],
            'moneda'                 => ['sometimes', 'string', 'size:3'],
            'numero_cuenta_completo' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
