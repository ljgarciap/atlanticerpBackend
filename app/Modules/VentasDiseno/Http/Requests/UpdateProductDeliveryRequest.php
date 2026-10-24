<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-909 — validación de forma (tipos/presencia). Pertenencia real (el `quote_item_id` es de
 * la cotización de ESTE proyecto, la fecha es una de las YA definidas para ESTA tarjeta) se valida
 * en `ProductDeliveryService::saveAssignments()`, que sí tiene el contexto de la tarjeta.
 */
class UpdateProductDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assignments'                    => ['required', 'array', 'min:1'],
            'assignments.*.quote_item_id'    => ['required', 'integer'],
            'assignments.*.delivery_date'    => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
