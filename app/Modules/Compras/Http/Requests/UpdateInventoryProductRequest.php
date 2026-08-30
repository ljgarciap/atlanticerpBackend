<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCRUM-237 (REQ-174) — detalle del producto, vista y edición modo Compras. RN4: información
 * general y precios son 2 secciones independientes con su propia confirmación — el frontend
 * decide si manda ambas juntas o por separado, este Request valida el conjunto que llegue
 * (`sometimes` en cada campo).
 */
class UpdateInventoryProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     *
     * Nombre de tabla sin calificar — ver docblock de StoreInventoryProductRequest::rules().
     */
    public function rules(): array
    {
        $productId = (int) $this->route('id');

        // `factory_reference` es `sometimes` igual que `provider_id` — el modal de detalle de hoy
        // (REQ-174) nunca manda ninguno de los dos, pero si un caller SÍ manda factory_reference
        // sin provider_id, `$this->input('provider_id')` da null y el scope de unicidad queda
        // "provider_id IS NULL" en vez del proveedor real del producto — dejaría colar un
        // factory_reference duplicado dentro del mismo proveedor. Fallback al valor ya persistido.
        $providerId = $this->has('provider_id')
            ? $this->input('provider_id')
            : CatalogProduct::find($productId)?->provider_id;

        return [
            'reference'         => ['sometimes', 'string', 'max:255', Rule::unique('catalog_products', 'reference')->ignore($productId)],
            'factory_reference' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::unique('catalog_products', 'factory_reference')
                    ->ignore($productId)
                    ->where(fn ($q) => $q->where('provider_id', $providerId)),
            ],
            // SCRUM-237 (rebote de Daniela Amaya 2026-08-12) — "Nombre del producto" y
            // "Descripción" son 2 campos independientes desde acá (ver migración
            // 2026_08_15_100000_...); antes editar `description` cambiaba el nombre visible en
            // toda la app porque era la misma columna.
            'name'              => ['sometimes', 'string', 'max:255'],
            'description'       => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brand'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'photo_url'         => ['sometimes', 'nullable', 'string', 'max:2048'],
            'price_full'        => ['sometimes', 'numeric', 'min:0'],
            'cost'              => ['sometimes', 'numeric', 'min:0'],
            'import_cost'       => ['sometimes', 'numeric', 'min:0'],
            'freight_cost'      => ['sometimes', 'numeric', 'min:0'],
            'handling_cost'     => ['sometimes', 'numeric', 'min:0'],
            'other_cost'        => ['sometimes', 'numeric', 'min:0'],
            'reorder_point'     => ['sometimes', 'nullable', 'integer', 'min:0'],
            'provider_id'       => ['sometimes', 'nullable', 'integer', 'min:1'],
            'rotation'          => ['sometimes', 'nullable', 'string', Rule::in(CatalogProduct::ROTATIONS)],
            // SCRUM-237 (hallazgo de Daniela Amaya 2026-08-09) — Categoría y Código de barras
            // pasan a ser editables desde la sección "Información del producto" del modal.
            'category'          => ['sometimes', 'nullable', 'string', Rule::in(CatalogProduct::CATEGORIES)],
            'barcode'           => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('catalog_products', 'barcode')->ignore($productId)],
            // SCRUM-237 — selector único de familia en el modal (N:M por debajo, ver
            // CatalogProduct::families(); `null` quita al producto de toda familia).
            'family_id'         => ['sometimes', 'nullable', 'integer', Rule::exists('catalog_product_families', 'id')],
        ];
    }
}
