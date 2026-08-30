<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCRUM-702 (REQ-622) — payload de `POST /ventas-diseno/catalog/send-pdf`. `product_ids` solo se
 * exige (y no vacío) cuando `mode=seleccionados` — en `mode=completo` se ignora cualquier valor
 * enviado (RN4: siempre TODOS los productos activos). `Rule::exists('catalog_products', 'id')`
 * sin calificar el schema — el `search_path` del tenant ya lo resuelve (mismo patrón que
 * `QuoteItemController`/`StorePurchaseOrderRequest`), scoped a `is_active` porque el Catálogo
 * comercial nunca ofrece productos inactivos para seleccionar.
 */
class SendCatalogPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(['completo', 'seleccionados'])],
            'product_ids' => ['required_if:mode,seleccionados', 'array', 'min:1'],
            'product_ids.*' => [
                'integer',
                Rule::exists('catalog_products', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
        ];
    }
}
