<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\Provider;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Modules\VentasDiseno\Services\CatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCRUM-240 (REQ-177) — crear producto nuevo desde Inventario, con stock inicial. A diferencia
 * de NewProductModal (Compras, nace "en camino"), este stock cuenta como disponible de inmediato
 * (RN1/Escenario 1) — nunca pasa por "Por ingresar".
 *
 * REQ-178 (unicidad de referencias): `reference` única global (constraint ya existe en DB desde
 * el Catálogo original); `factory_reference` única solo dentro del mismo `provider_id` (índice
 * parcial ya existente, sprint2) — ambas validadas acá para devolver 422 claro en vez de dejar
 * que la constraint de DB tire un 500.
 *
 * Reescritura completa 2026-08-12 (corrección de Daniela Amaya 2026-08-09, mockup/requerimiento
 * completo) — campos/orden/obligatoriedad alineados al modal nuevo:
 * - `provider_id`/`factory_reference`/`brand`/`barcode`/`category`/`rotation`/`reorder_point`
 *   ("Stock mínimo") pasan de opcionales a OBLIGATORIOS.
 * - `family_id` nuevo, opcional (relación N:M vía `CatalogProduct::families()`, no columna —
 *   mismo patrón que `InventoryController::update()` ya usa).
 * - `initial_stock` reemplaza `stock_quantity`+`warehouse_id` (una sola bodega): ahora es un
 *   array opcional de `{warehouse_id, quantity}`, una entrada por cada bodega con stock inicial
 *   > 0 — RN del ticket ("Stock inicial (opcional)... un campo por cada bodega... puede dejarlo
 *   en 0"). `stock_quantity` de la columna se sigue derivando (suma), no lo manda el cliente.
 * - `other_cost` ("Costo adicional estimado") ahora obligatorio — el frontend resuelve el
 *   toggle $/% (mismo patrón que Nueva Orden, REQ-131) a un monto en dólares antes de enviarlo;
 *   el backend solo persiste el resultado, no conoce el toggle.
 *
 * SCRUM-426 (REQ-356, rebote de Daniela Amaya 2026-08-13) — la reescritura de SCRUM-240
 * (2026-08-12) sacó los 12 campos estructurados de `technical_spec` del formulario y los
 * reemplazó por el botón "Cargar ficha técnica" (documento/link, mecanismo de SCRUM-237), pero
 * dejó ese paso completamente opcional — el endpoint no exigía ni `technical_spec` NI el
 * documento/link nuevo, así que cualquier producto podía crearse sin ficha técnica de ningún
 * tipo, regresión real contra el REQ original (ficha técnica obligatoria). Fix: el documento/link
 * de SCRUM-237 (`file`/`link`, mismas reglas que `StoreInventoryProductTechnicalSheetRequest`)
 * pasa a ser OBLIGATORIO — exactamente uno de los dos — DENTRO de este mismo request, para que
 * `InventoryController::store()` lo procese en la misma transacción que crea el producto (nunca
 * un producto sin ficha técnica en la base, ni siquiera transitoriamente entre 2 llamadas).
 */
class StoreInventoryProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reference'         => ['required', 'string', 'max:255', $this->uniquePublicReferenceRule()],
            'factory_reference' => ['required', 'string', 'max:255', $this->uniqueFactoryReferenceRule()],
            // SCRUM-240 (rebote de Daniela Amaya 2026-08-12) — "Nombre del producto" y
            // "Descripción" pasan a ser 2 campos independientes (ver migración
            // 2026_08_15_100000_...); `description` deja de ser obligatorio, el nombre es lo único
            // que siempre identifica al producto.
            'name'              => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string', 'max:2000'],
            'brand'             => ['required', 'string', 'max:255'],
            'barcode'           => ['required', 'string', 'max:255'],
            'photo_url'         => ['nullable', 'string', 'max:2048'],
            'price_full'        => ['required', 'numeric', 'min:0'],
            'cost'              => ['required', 'numeric', 'min:0'],
            'other_cost'        => ['required', 'numeric', 'min:0'],
            'reorder_point'     => ['required', 'integer', 'min:0'],
            'provider_id'       => ['required', 'integer', 'min:1', $this->existsRule(Provider::class)],
            'family_id'         => ['nullable', 'integer', 'min:1', $this->existsRule(CatalogProductFamily::class)],
            'category'          => ['required', 'string', Rule::in(CatalogProduct::CATEGORIES)],
            'rotation'          => ['required', 'string', Rule::in(CatalogProduct::ROTATIONS)],
            'initial_stock'                   => ['nullable', 'array'],
            'initial_stock.*.warehouse_id'    => ['required', 'integer', 'min:1', $this->existsRule(Warehouse::class)],
            'initial_stock.*.quantity'        => ['required', 'integer', 'min:0'],
            // SCRUM-426 — mismas reglas que StoreInventoryProductTechnicalSheetRequest (Caso A/B:
            // archivo O link, nunca ambos ni ninguno).
            'file' => [
                Rule::requiredIf(fn (): bool => ! $this->filled('link')),
                'nullable', 'file',
                'extensions:'.implode(',', CatalogService::TECHNICAL_SHEET_EXTENSIONS),
            ],
            'link' => [
                Rule::requiredIf(fn (): bool => ! $this->hasFile('file')),
                'nullable', 'string', 'url', 'max:2048',
            ],
        ];
    }

    /**
     * `Rule::exists()` interpreta un string con punto ("{tenant}_compras.providers") como
     * "connection.table" (busca una CONEXIÓN de Laravel llamada así), no como schema.tabla de
     * Postgres — este proyecto es schema-per-tenant sobre una única conexión (ver
     * `ProductWarehouseStock::getTable()`/`Provider::getTable()`, que ya resuelven el schema
     * correcto). Reemplazo: closure que consulta el modelo real (mismo patrón que
     * `uniquePublicReferenceRule()`/`uniqueFactoryReferenceRule()` de esta misma clase).
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function existsRule(string $modelClass): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($modelClass): void {
            if (! is_numeric($value)) {
                return;
            }
            if ($modelClass::find((int) $value) === null) {
                $fail('El valor seleccionado no es válido.');
            }
        };
    }

    /**
     * REQ-178 Escenario 1 — hallazgo de QA (2026-07-18): el mensaje debe indicar A QUÉ PRODUCTO
     * pertenece la referencia, no el genérico de Laravel ("El reference ya esta en uso.", con el
     * nombre del campo en inglés crudo). Mismo texto que StorePurchaseOrderRequest (Nueva Orden),
     * único precedente correcto ya existente en el módulo — no dos mensajes distintos para el
     * mismo error.
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

    /** REQ-178 Escenarios 2/3 — única solo dentro del mismo proveedor, mismo criterio que Nueva Orden. */
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
            $existing = CatalogProduct::where('provider_id', (int) $providerId)
                ->where('factory_reference', $value)
                ->first();
            if ($existing !== null) {
                $fail("La referencia de fábrica '{$value}' ya pertenece al producto '{$existing->name}' de este proveedor.");
            }
        };
    }
}
