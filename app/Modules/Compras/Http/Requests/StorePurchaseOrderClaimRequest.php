<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\Compras\Models\PurchaseOrderClaim;
use App\Modules\Compras\Models\PurchaseOrderLine;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SCRUM-260 (REQ-197) Escenario 1/2 — no se puede repetir un producto en el mismo reclamo, ni
 * declarar más unidades afectadas que las pedidas en esa línea de la orden.
 */
class StorePurchaseOrderClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'description' => ['nullable', 'string'],
            // SCRUM-260 (hallazgo de Gerencia Test) — deja de ser texto libre, solo las 5
            // opciones fijas del requerimiento.
            'expected_resolution' => ['nullable', 'string', Rule::in(PurchaseOrderClaim::RESOLUTION_TYPES)],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'exists:purchase_order_lines,id'],
            'lines.*.affected_quantity' => ['required', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        /** @var Validator $validator */
        $validator->after(function (Validator $validator): void {
            $lines = $this->input('lines', []);
            if (! is_array($lines)) {
                return;
            }

            $seen = [];
            foreach ($lines as $i => $line) {
                $lineId = $line['purchase_order_line_id'] ?? null;
                if ($lineId === null) {
                    continue;
                }

                if (in_array($lineId, $seen, true)) {
                    $validator->errors()->add("lines.{$i}", 'No se puede repetir el mismo producto en un reclamo.');

                    continue;
                }
                $seen[] = $lineId;

                $orderLine = PurchaseOrderLine::find((int) $lineId);

                // Cada línea del reclamo debe ser de LA orden reclamada: antes se aceptaba una línea de otra orden.
                if ($orderLine !== null && is_numeric($this->input('purchase_order_id')) && (int) $orderLine->purchase_order_id !== (int) $this->input('purchase_order_id')) {
                    $validator->errors()->add("lines.{$i}.purchase_order_line_id", 'Esa línea no pertenece a la orden del reclamo.');

                    continue;
                }

                // Fase 13: la línea de ajuste del cambio de monto no es mercancía: no se reclama.
                if ($orderLine !== null && $orderLine->is_adjustment) {
                    $validator->errors()->add("lines.{$i}.purchase_order_line_id", 'Esa línea es un ajuste de monto, no un producto.');

                    continue;
                }

                $affected = (int) ($line['affected_quantity'] ?? 0);
                if ($orderLine !== null && $affected > $orderLine->quantity) {
                    $validator->errors()->add(
                        "lines.{$i}.affected_quantity",
                        "El máximo permitido para este producto es {$orderLine->quantity}.",
                    );
                }
            }
        });
    }
}
