<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Fase 12a — reabrir un ticket cerrado o cancelado exige un motivo explícito (mínimo 10 caracteres, como «Ver como usuario»). */
class ReopenTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
