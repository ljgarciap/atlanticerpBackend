<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RN1 de REQ-559: el detalle de una tasa solo permite activar/desactivar — no se puede cambiar
 * porcentaje/nombre/descripción de una tasa ya creada por este endpoint (ningún REQ lo pide).
 */
class UpdateItbmsRateRequest extends FormRequest
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
