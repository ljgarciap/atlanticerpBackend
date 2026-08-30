<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524 RN4) — observación general del día. */
class UpdateDailyCashCountGeneralObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'observacion' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
