<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use App\Modules\Servicios\Models\ServiceQuote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** REQ-234 — Aprobar/Rechazar, exclusivo de Aaron/lider_servicios (ver `role:` en la ruta). */
class DecideServiceQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::in(ServiceQuote::ESTADOS_DECISION)],
        ];
    }
}
