<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// REQ-027: nombre obligatorio, acotado a un Subcliente ya elegido; Etiqueta opcional.
class StoreSalesProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sub_client_id' => ['required', 'integer', Rule::exists('sub_clients', 'id')],
            'name'          => ['required', 'string', 'max:255'],
            'tag'           => ['nullable', 'string', Rule::in(['design', 'quote', 'both'])],
        ];
    }
}
