<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Models\Architect;
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
            // SCRUM-1121: la base de asociados es global, el subcliente de origen ya no es obligatorio.
            'sub_client_id' => ['nullable', 'integer', Rule::exists('sub_clients', 'id')],
            'type'          => ['sometimes', Rule::in(Architect::TYPES)],
            'name'          => ['required', 'string', 'max:255'],
            'phone'         => ['nullable', 'required_without_all:email,document', 'string', 'max:50'],
            'email'         => ['nullable', 'required_without_all:phone,document', 'email', 'max:255'],
            'document'      => ['nullable', 'required_without_all:phone,email', 'string', 'max:100'],
        ];
    }
}
