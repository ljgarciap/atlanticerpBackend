<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 21 (REQ-545 RN2) — soporte adicional para una línea ya registrada; mismas reglas de
 *  archivo que la foto obligatoria de `StorePettyCashExpensesRequest`. */
class AddPettyCashExpenseAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'foto' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }
}
