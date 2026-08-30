<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-516 RN3 — motivo obligatorio, % entre 0 (exclusivo) y 100. */
class ProposeArchitectPercentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'porcentaje' => ['required', 'numeric', 'gt:0', 'max:100'],
            'motivo'     => ['required', 'string', 'max:500'],
        ];
    }
}
