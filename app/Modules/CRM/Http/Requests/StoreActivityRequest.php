<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo'      => ['required', 'string', Rule::in(['llamada', 'email', 'reunion', 'whatsapp', 'visita', 'otro'])],
            'fecha'     => ['required', 'date'],
            'notas'     => ['nullable', 'string'],
            'siguiente' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
