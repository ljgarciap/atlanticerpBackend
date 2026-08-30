<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 21 (REQ-545 RN4) — edición de línea; `solicitante_id` deliberadamente no está en las
 *  reglas — nunca es un campo editable, el service tampoco lo toca aunque llegue en el body. */
class UpdatePettyCashExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'proveedor' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string'],
            'monto_bruto' => ['required', 'numeric', 'min:0'],
            'itbms' => ['required', 'numeric', 'min:0'],
        ];
    }
}
