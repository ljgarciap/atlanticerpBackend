<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Rules\SafePhotoUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * SCRUM-1126 — con `provider_ids` y sin `provider_id`, el principal es el actual si sigue en la lista, si
     * no el primero; lista vacía => 422 (un producto conserva al menos un proveedor, igual que al crear;
     * quitar el proveedor sigue siendo posible solo enviando `provider_id: null` explícito). Así el scope de unicidad de `factory_reference` y el
     * controlador ven siempre el principal efectivo.
     */
    protected function prepareForValidation(): void
    {
        $ids = $this->input('provider_ids');
        if (! is_array($ids) || $this->has('provider_id')) {
            return;
        }
        $ids = array_values(array_filter(array_map(fn ($v) => is_numeric($v) ? (int) $v : null, $ids)));
        if ($ids === []) {
            $this->merge(['provider_id' => null]);

            return;
        }
        $current = CatalogProduct::find((int) $this->route('id'))?->provider_id;
        $this->merge(['provider_id' => in_array($current, $ids, true) ? $current : $ids[0]]);
    }

    /**
     * Unicidad de `factory_reference` por proveedor (REQ-178/131), ahora contra TODOS los proveedores
     * efectivos del producto tras el PUT (principal + pivote, SCRUM-1126 Senior Review). Cubre también el
     * caso de cambiar proveedor(es) SIN reenviar `factory_reference` (se usa el persistido): antes el índice
     * único de BD devolvía un 500. Si la referencia no cambia, solo se valida contra proveedores NUEVOS, para
     * no bloquear ediciones no relacionadas por duplicados históricos.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->hasAny(['factory_reference', 'provider_id', 'provider_ids', 'provider_ids.*'])) {
                return;
            }
            $touchesProviders = $this->has('provider_id') || $this->has('provider_ids');
            if (! $touchesProviders && ! $this->has('factory_reference')) {
                return;
            }

            $product = CatalogProduct::find((int) $this->route('id'));
            if ($product === null) {
                return;
            }

            $current = array_map('intval', $product->providers()->pluck('provider_id')->all());
            if ($product->provider_id !== null) {
                $current[] = (int) $product->provider_id;
            }
            $current = array_values(array_unique($current));

            // Proveedores efectivos tras el update (misma lógica que InventoryController::update()).
            if ($this->has('provider_ids')) {
                $effective = array_map('intval', (array) $this->input('provider_ids'));
                if (is_numeric($this->input('provider_id'))) {
                    $effective[] = (int) $this->input('provider_id');
                }
            } elseif ($this->has('provider_id')) {
                $effective = is_numeric($this->input('provider_id'))
                    ? array_merge($current, [(int) $this->input('provider_id')])
                    : [];
            } else {
                $effective = $current;
            }
            $effective = array_values(array_unique($effective));

            $persistedRef = $product->factory_reference;
            $ref = $this->has('factory_reference') ? $this->input('factory_reference') : $persistedRef;
            if (! is_string($ref) || $ref === '') {
                return;
            }

            $toCheck = $ref !== $persistedRef ? $effective : array_values(array_diff($effective, $current));
            $existing = CatalogProduct::findByFactoryReferenceForProviders($ref, $toCheck, $product->id);
            if ($existing !== null) {
                $v->errors()->add('factory_reference', "La referencia de fábrica '{$ref}' ya pertenece al producto '{$existing->name}' de este proveedor.");
            }
        });
    }

    private function providerExistsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_numeric($value) && Provider::find((int) $value) === null) {
                $fail('El valor seleccionado no es válido.');
            }
        };
    }

    /**
     * @return array<string, mixed>
     *
     * Nombre de tabla sin calificar — ver docblock de StoreInventoryProductRequest::rules().
     */
    public function rules(): array
    {
        $productId = (int) $this->route('id');

        return [
            'reference' => ['sometimes', 'string', 'max:255', Rule::unique('catalog_products', 'reference')->ignore($productId)],
            // Unicidad por proveedor (principal o secundario): ver withValidator().
            'factory_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            // SCRUM-237 (rebote de Gerencia Test 2026-08-12) — "Nombre del producto" y
            // "Descripción" son 2 campos independientes desde acá (ver migración
            // 2026_08_15_100000_...); antes editar `description` cambiaba el nombre visible en
            // toda la app porque era la misma columna.
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'photo_url' => ['sometimes', 'nullable', 'string', 'max:2048', new SafePhotoUrl],
            'price_full' => ['sometimes', 'numeric', 'min:0'],
            'cost' => ['sometimes', 'numeric', 'min:0'],
            'import_cost' => ['sometimes', 'numeric', 'min:0'],
            'freight_cost' => ['sometimes', 'numeric', 'min:0'],
            'handling_cost' => ['sometimes', 'numeric', 'min:0'],
            'other_cost' => ['sometimes', 'numeric', 'min:0'],
            'reorder_point' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'provider_id' => ['sometimes', 'nullable', 'integer', 'min:1', $this->providerExistsRule()],
            // SCRUM-1126 — todos los proveedores del producto (principal incluido); [] = sin proveedores.
            'provider_ids' => ['sometimes', 'array', 'min:1'],
            'provider_ids.*' => ['integer', 'min:1', 'distinct', $this->providerExistsRule()],
            'rotation' => ['sometimes', 'nullable', 'string', Rule::in(CatalogProduct::ROTATIONS)],
            // SCRUM-237 (hallazgo de Gerencia Test 2026-08-09) — Categoría y Código de barras
            // pasan a ser editables desde la sección "Información del producto" del modal.
            'category' => ['sometimes', 'nullable', 'string', Rule::in(\App\Shared\Catalogs\CatalogService::keys('product_category'))],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('catalog_products', 'barcode')->ignore($productId)],
            // SCRUM-237 — selector único de familia en el modal (N:M por debajo, ver
            // CatalogProduct::families(); `null` quita al producto de toda familia).
            'family_id' => ['sometimes', 'nullable', 'integer', Rule::exists('catalog_product_families', 'id')],
        ];
    }
}
