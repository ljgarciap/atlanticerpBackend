<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use App\Modules\Servicios\Models\ServiceQuoteItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-230/231/232 — ítem de Cotización. Validación de forma únicamente (tipo/rangos); las reglas
 * de negocio por tipo (duplicado, margen, técnico Activo) viven en
 * `ServiceQuoteService::resolveItemData()`, que necesita cargar el catálogo/técnico real para
 * resolverlas — no duplicables acá sin una segunda consulta.
 */
class StoreServiceQuoteItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tipo'                    => ['required', 'string', Rule::in(ServiceQuoteItem::TIPOS)],
            'catalog_product_id'      => ['nullable', 'integer'],
            'is_custom'               => ['nullable', 'boolean'],
            'external_technician_id'  => ['nullable', 'integer'],
            'description'             => ['nullable', 'string', 'max:1000'],
            'quantity'                => ['required', 'numeric', 'min:0.01'],
            'unit_price'              => ['nullable', 'numeric', 'min:0'],
            'margin_percent'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            // SCRUM-781 (punto 3) — override de costo/día por ítem, solo aplica a subcontratado
            // (ServiceQuoteService::resolveSubcontractedItem() ignora esto en product/labor).
            'cost_reference'          => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
