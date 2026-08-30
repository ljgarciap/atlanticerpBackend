<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-233 — "Guardar" sobre el Borrador: descuento (%) y observaciones. Nunca cambia el estado
 * (ver `ServiceQuoteService::save()`).
 */
class SaveServiceQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'observations'     => ['sometimes', 'nullable', 'string'],
        ];
    }
}
