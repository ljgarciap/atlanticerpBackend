<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Ver docblock de `StoreServiceQuoteItemRequest` — mismos campos, todos opcionales (el tipo de la línea no se reasigna, ver `ServiceQuoteService::updateItem()`). */
class UpdateServiceQuoteItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'catalog_product_id'      => ['nullable', 'integer'],
            'is_custom'               => ['nullable', 'boolean'],
            'external_technician_id'  => ['nullable', 'integer'],
            'description'             => ['nullable', 'string', 'max:1000'],
            'quantity'                => ['sometimes', 'numeric', 'min:0.01'],
            'unit_price'              => ['nullable', 'numeric', 'min:0'],
            'margin_percent'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            // SCRUM-781 (punto 3) — override de costo/día por ítem, solo aplica a subcontratado.
            'cost_reference'          => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
