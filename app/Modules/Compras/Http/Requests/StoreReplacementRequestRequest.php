<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-184 — "Generar solicitud" desde la tabla de resultados (REQ-183). `sales_project_id` es
 * requerido a propósito: el Excel de requerimientos marca la falta de este campo como la 4ta
 * repetición del mismo bug (REQ-118/179/180). El picker restringido a Pedidos Aprobados ya lo
 * resuelve del lado del frontend, pero la regla dura de REQ-133 (solo Pedidos Aprobados) se
 * revalida acá también — mismo criterio que StorePurchaseOrderRequest::approvedProjectRule(),
 * nunca confiar solo en que el frontend mandó un id "legítimo".
 */
class StoreReplacementRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'original_catalog_product_id' => ['required', 'integer'],
            'proposed_catalog_product_id' => ['required', 'integer', 'different:original_catalog_product_id'],
            'sales_project_id'            => ['required', 'integer', $this->approvedProjectRule()],
        ];
    }

    /** REQ-133 RN dura: solo se pueden asignar Pedidos ya Aprobados. */
    private function approvedProjectRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $quote = Quote::where('sales_project_id', $value)->first();
            if ($quote === null || $quote->documentStatus() !== 'approved') {
                $fail('Solo se pueden asignar Pedidos en estado Aprobado.');
            }
        };
    }
}
