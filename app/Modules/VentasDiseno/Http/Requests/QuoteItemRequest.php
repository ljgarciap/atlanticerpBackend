<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-035/036/041/042 — compartida entre store y update. price_type_override
 * nunca "partner" (REQ-042: restringido por perfil, ver REQ-034).
 *
 * `reference`/`description`/`unit_price` son `required_without:
 * catalog_product_id` — REQ-035: sin producto de catálogo seleccionado, el
 * usuario los tipea a mano (ítem personalizado); con un `catalog_product_id`
 * válido, el controller los resuelve server-side desde el producto real
 * (nunca confía en lo que mande el cliente para esos campos, ni para `cost`
 * — ver QuoteItemController). `unit_price_override` (SCRUM-725) es el único
 * canal para pisar el precio de un ítem de CATÁLOGO dentro de esta cotización
 * — deliberadamente separado de `unit_price` (que sigue siendo solo el precio
 * de un ítem personalizado) para no reabrir la puerta que este mismo ticket
 * cerró: un `unit_price` mandado "de arrastre" junto a un catalog_product_id
 * ya no se usa ni por accidente.
 */
class QuoteItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'catalog_product_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('catalog_products', 'id')],
            'reference'           => ['required_without:catalog_product_id', 'string', 'max:255'],
            'description'         => ['required_without:catalog_product_id', 'string', 'max:255'],
            'quantity'            => ['required', 'numeric', 'min:0.01'],
            'unit_price'          => ['required_without:catalog_product_id', 'numeric', 'min:0'],
            'unit_price_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost'                => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'price_type_override' => ['sometimes', 'nullable', 'string', Rule::in(['public', 'project', 'special', 'premium'])],
            'discount_percent'    => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
