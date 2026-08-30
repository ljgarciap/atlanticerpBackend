<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-058 — reusado para store y update (mismo patrón que StorePipelineCardContactRequest).
 * `owner_id` opcional: si no se manda, el controller usa al usuario en sesión; solo
 * Gerencia/Superadmin puede fijarlo a otro vendedor (mismo criterio de alcance que
 * REQ-056), el controller valida ese permiso, no esta Request.
 */
class StoreCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_id'    => ['nullable', 'integer', 'exists:users,id'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_at'    => ['required', 'date'],
            'end_at'      => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day'     => ['sometimes', 'boolean'],
        ];
    }
}
