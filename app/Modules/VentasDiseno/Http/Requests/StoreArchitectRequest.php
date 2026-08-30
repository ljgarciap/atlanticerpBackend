<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// REQ-030 / SCRUM-122: acotado a un Subcliente ya elegido (mismo patrón que
// StoreSalesProjectRequest); nombre obligatorio, al menos uno de teléfono/correo.
class StoreArchitectRequest extends FormRequest
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
            'phone'         => ['nullable', 'required_without:email', 'string', 'max:50'],
            'email'         => ['nullable', 'required_without:phone', 'email', 'max:255'],
        ];
    }
}
