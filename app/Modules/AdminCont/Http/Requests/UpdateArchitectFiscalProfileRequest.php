<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\ArchitectFiscalProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** REQ-510 RN1 — los 3 campos son obligatorios en conjunto, nunca se guarda con solo alguno. */
class UpdateArchitectFiscalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'empresa'        => ['required', 'string', 'max:255'],
            'ruc'            => ['required', 'string', 'max:100'],
            'regimen_fiscal' => ['required', 'string', Rule::in(ArchitectFiscalProfile::REGIMENES)],
        ];
    }
}
