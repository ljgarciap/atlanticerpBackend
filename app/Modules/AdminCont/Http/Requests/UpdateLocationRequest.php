<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RN4 de REQ-563 — desactivar/reactivar es la única operación soportada desde el detalle. */
class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'activa' => ['required', 'boolean'],
        ];
    }
}
