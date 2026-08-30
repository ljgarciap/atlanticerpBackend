<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-516 RN4 — rechazo exige motivo (no se persiste, ver docblock de
 *  ArchitectCommissionService::decidirPorcentaje() — mismo criterio que el resto del REQ, el
 *  motivo es para que quien rechaza lo justifique en el momento, no queda como historial). */
class DecideArchitectPercentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approve'         => ['required', 'boolean'],
            'motivo_rechazo'  => ['required_if:approve,false', 'nullable', 'string', 'max:500'],
        ];
    }
}
