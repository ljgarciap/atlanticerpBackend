<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * REQ-128→133 — Nueva Orden (2H), envío completo del wizard: proveedor + líneas (producto
 * existente del catálogo O producto nuevo, nunca ambos ni ninguno) + proyecto opcional por línea.
 *
 * RN3 de REQ-118 (aplica acá, ver docs/specs/compras-proveedores-sprint2.md hallazgo 5): no se
 * valida acá, se calcula en el controller (PurchaseOrder::computeRequiresPrimaryApproval()) — es
 * una regla de negocio derivada, no una validación de forma.
 */
class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * SCRUM-1145 (Pre-QA) — nombres en español de los costos adicionales para los mensajes 422 (sin esto salían
     * "The import cost field must be at least 0").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'import_cost' => 'costo de importación',
            'freight_cost' => 'flete',
            'handling_cost' => 'manejo',
            'other_cost' => 'otros costos',
            // SCRUM-1184 (Pre-QA B5) — el 422 por una cantidad enorme mostraba «lines.0.quantity».
            'lines.*.quantity' => 'cantidad',
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'integer', Rule::exists('providers', 'id')],
            'shipping_type' => ['nullable', 'string', Rule::in(['aereo', 'maritimo', 'terrestre'])],
            // SCRUM-197 (REQ-134, hallazgo de Gerencia Test 2026-07-30): el mockup solo contempla
            // Cliente/Atlantic — 'proveedor' no existe en el requerimiento, se corrige acá.
            'who_pays_shipping' => ['nullable', 'string', Rule::in(['atlantic', 'cliente'])],
            // SCRUM-197 — RN de REQ-134: envío aéreo captura costo y quién lo paga.
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            // SCRUM-1145 — costos adicionales opcionales de la orden.
            'import_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'freight_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'handling_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'other_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'modality' => ['nullable', 'string', Rule::in(['directo', 'zona_libre'])],
            'estimated_arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_product_id' => ['nullable', 'integer', Rule::exists('catalog_products', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'lines.*.new_product' => ['nullable', 'array'],
            'lines.*.new_product.reference' => ['required_with:lines.*.new_product', 'string', 'max:255', $this->uniquePublicReferenceRule()],
            'lines.*.new_product.factory_reference' => ['nullable', 'string', 'max:255', $this->uniqueFactoryReferenceRule()],
            // SCRUM-237/240 — "Nombre del producto" y "Descripción" son 2 campos independientes
            // (ver migración 2026_08_15_100000_...); el alta inline de un producto nuevo desde
            // Nueva Orden sigue el mismo criterio que Inventario (nombre obligatorio, descripción
            // libre y opcional).
            'lines.*.new_product.name' => ['required_with:lines.*.new_product', 'string', 'max:255'],
            'lines.*.new_product.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.new_product.brand' => ['nullable', 'string', 'max:255'],
            'lines.*.new_product.price_full' => ['required_with:lines.*.new_product', 'numeric', 'min:0'],
            'lines.*.new_product.cost' => ['required_with:lines.*.new_product', 'numeric', 'min:0'],
            'lines.*.new_product.reorder_point' => ['nullable', 'integer', 'min:0'],
            // SCRUM-194 (REQ-131, hallazgo de Gerencia Test 2026-07-30) — Categoría/Rotación
            // esperada del mockup, ausentes hasta ahora en el alta de producto nuevo.
            'lines.*.new_product.category' => ['nullable', 'string', Rule::in(CatalogProduct::CATEGORIES)],
            'lines.*.new_product.rotation' => ['nullable', 'string', Rule::in(CatalogProduct::ROTATIONS)],
            // Tope técnico: la columna `quantity` es integer (SCRUM-1184, Pre-QA: 999999999999 llegaba a la BD y devolvía un 500 con el SQL a la vista).
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_INT_COLUMN],
            // SCRUM-193 (REQ-130, hallazgo de Gerencia Test) — costo precargado del catálogo pero
            // editable por línea (ej. precio renegociado para esta orden puntual); si no se manda,
            // el controller sigue tomando el costo real del producto (compatibilidad).
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.additional_cost_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // SCRUM-194 — toggle $ monto / % del costo del mockup; sustituye el % único que
            // existía. `additional_cost_percent` se mantiene por compatibilidad con órdenes viejas.
            'lines.*.additional_cost_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.additional_cost_type' => ['nullable', 'string', Rule::in(['monto', 'porcentaje'])],
            'lines.*.sales_project_id' => ['nullable', 'integer', $this->approvedProjectRule()],
        ];
    }

    /**
     * REQ-131 Escenario 3: referencia pública única globalmente, sin excepción — a diferencia
     * de la de fábrica, no importa el proveedor.
     */
    private function uniquePublicReferenceRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $existing = CatalogProduct::where('reference', $value)->first();
            if ($existing !== null) {
                $fail("La referencia pública '{$value}' ya pertenece al producto '{$existing->name}'.");
            }
        };
    }

    /**
     * REQ-131 Escenarios 1/2: única solo DENTRO del mismo proveedor — la misma referencia de
     * fábrica puede repetirse entre proveedores distintos.
     */
    private function uniqueFactoryReferenceRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }
            $providerId = $this->input('provider_id');
            if (! is_numeric($providerId)) {
                return;
            }
            // SCRUM-1126 — también cuenta el producto que tiene a este proveedor como secundario (pivote).
            $existing = CatalogProduct::findByFactoryReferenceForProviders($value, [(int) $providerId]);
            if ($existing !== null) {
                $fail("La referencia de fábrica '{$value}' ya pertenece al producto '{$existing->name}' de este proveedor.");
            }
        };
    }

    /** REQ-133 RN dura: solo se pueden asignar Pedidos ya Aprobados. */
    private function approvedProjectRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null) {
                return;
            }
            $quote = Quote::where('sales_project_id', $value)->first();
            if ($quote === null || $quote->documentStatus() !== 'approved') {
                $fail('Solo se pueden asignar Pedidos en estado Aprobado.');
            }
        };
    }

    /** Límite de una columna `integer` de PostgreSQL. */
    private const MAX_INT_COLUMN = 2147483647;

    /** Límite de `subtotal` (`decimal(12,2)`): 10 dígitos enteros. */
    private const SUBTOTAL_LIMIT = 10_000_000_000;

    /**
     * SCRUM-1184 (Pre-QA M3) — cantidad × costo de una línea tiene que caber en `purchase_order_lines.subtotal`; si no, el INSERT fallaba con un 500.
     * El costo es el editado en la línea o, si no viene, el del producto de catálogo (misma regla que el controller).
     *
     * @param  array<int|string, mixed>  $lines
     */
    private function validateLineSubtotalsFitTheColumn(Validator $validator, array $lines): void
    {
        if ($validator->errors()->has('lines.*.quantity')) {
            return;
        }

        $productIds = [];
        foreach ($lines as $line) {
            if (is_array($line) && ! isset($line['unit_cost']) && ! empty($line['catalog_product_id'])) {
                $productIds[] = (int) $line['catalog_product_id'];
            }
        }
        $costs = $productIds === [] ? [] : CatalogProduct::query()->whereIn('id', array_unique($productIds))->pluck('cost', 'id')->all();

        foreach ($lines as $i => $line) {
            if (! is_array($line) || ! is_numeric($line['quantity'] ?? null) || $validator->errors()->has("lines.{$i}.quantity")) {
                continue;
            }
            $unitCost = isset($line['unit_cost']) && is_numeric($line['unit_cost'])
                ? (float) $line['unit_cost']
                : (float) ($costs[(int) ($line['catalog_product_id'] ?? 0)] ?? 0);

            if ($unitCost * (int) $line['quantity'] >= self::SUBTOTAL_LIMIT) {
                $validator->errors()->add("lines.{$i}.quantity", 'La cantidad por el costo de esta línea supera el máximo que admite una orden.');
            }
        }
    }

    public function withValidator(ValidatorContract $validator): void
    {
        /** @var Validator $validator */
        $validator->after(function (Validator $validator): void {
            $lines = $this->input('lines', []);
            if (! is_array($lines)) {
                return;
            }
            foreach ($lines as $i => $line) {
                $hasCatalogProduct = ! empty($line['catalog_product_id'] ?? null);
                $hasNewProduct = ! empty($line['new_product'] ?? null);
                if ($hasCatalogProduct === $hasNewProduct) {
                    $validator->errors()->add(
                        "lines.{$i}",
                        'Cada línea debe tener exactamente un producto: existente (catalog_product_id) o nuevo (new_product).',
                    );
                }
            }

            $this->validateLineSubtotalsFitTheColumn($validator, $lines);
            $this->validateNewProductReferencesAreUniqueWithinPayload($validator, $lines);
            $this->validateCatalogProductIdsAreUniqueWithinPayload($validator, $lines);
        });
    }

    /**
     * SCRUM-861 punto 1 — un mismo producto existente no puede aparecer en 2+ líneas de la misma
     * orden (el frontend ya lo evita ocultando el producto de la búsqueda una vez agregado, pero
     * la validación de forma no dependía de eso — solo `Rule::exists`, sin `distinct`).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateCatalogProductIdsAreUniqueWithinPayload(Validator $validator, array $lines): void
    {
        $seenProductIds = [];
        foreach ($lines as $i => $line) {
            $catalogProductId = $line['catalog_product_id'] ?? null;
            if (empty($catalogProductId)) {
                continue;
            }

            if (isset($seenProductIds[$catalogProductId])) {
                $validator->errors()->add(
                    "lines.{$i}.catalog_product_id",
                    'Este producto ya está agregado en otra línea de la orden — modificá la cantidad de esa línea en vez de volver a seleccionarlo.',
                );
            }
            $seenProductIds[$catalogProductId] = true;
        }
    }

    /**
     * SCRUM-194 (REQ-131, hallazgo real de Gerencia Test 2026-07-30): `uniquePublicReferenceRule()`/
     * `uniqueFactoryReferenceRule()` solo consultan la BD — dos líneas `new_product` NUEVAS en el
     * MISMO request con la misma referencia pasan ambas (ninguna existe todavía en `catalog_products`
     * al momento de validar), y `createProduct()` termina creando 2 productos duplicados. Pre-QA
     * (2026-07-17) solo había probado la colisión contra un producto YA existente en BD, nunca este
     * caso — no es la misma regresión, es un escenario que nunca se cubrió.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateNewProductReferencesAreUniqueWithinPayload(Validator $validator, array $lines): void
    {
        $seenPublicRefs = [];
        $seenFactoryRefs = [];
        foreach ($lines as $i => $line) {
            $newProduct = $line['new_product'] ?? null;
            if (! is_array($newProduct)) {
                continue;
            }

            $reference = $newProduct['reference'] ?? null;
            if (is_string($reference) && $reference !== '') {
                if (isset($seenPublicRefs[$reference])) {
                    $validator->errors()->add(
                        "lines.{$i}.new_product.reference",
                        "La referencia pública '{$reference}' se repite en más de un producto nuevo de esta misma orden.",
                    );
                }
                $seenPublicRefs[$reference] = true;
            }

            $factoryReference = $newProduct['factory_reference'] ?? null;
            if (is_string($factoryReference) && $factoryReference !== '') {
                if (isset($seenFactoryRefs[$factoryReference])) {
                    $validator->errors()->add(
                        "lines.{$i}.new_product.factory_reference",
                        "La referencia de fábrica '{$factoryReference}' se repite en más de un producto nuevo de esta misma orden.",
                    );
                }
                // REQ-131 Escenario 2: única solo dentro del mismo proveedor — pero acá TODAS las
                // líneas de la orden comparten el mismo `provider_id`, así que cualquier repetición
                // entre new_product de este payload ya es del mismo proveedor por definición.
                $seenFactoryRefs[$factoryReference] = true;
            }
        }
    }
}
