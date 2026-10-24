<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Modules\Bodega\Models\Order;

/**
 * Batch 2 de Facturación — preview/store aceptan 1..N entregas a la vez ("Guardar factura(s)",
 * REQ-441 Escenario 3). `aplicar_saldo_favor` (Batch 3, REQ-442) se retiró en SCRUM-902: una
 * factura ahora se genera SIEMPRE por su monto completo, sin ninguna aplicación de saldo a favor
 * en este paso — ver `App\Modules\AdminCont\Services\AbonoService::reasociarAlFacturar()`, que
 * re-asocia (sin modificar la factura) los abonos de nivel Proyecto a la factura recién generada;
 * la aplicación real ocurre únicamente en Cobros.
 *
 * SCRUM-929 — `modo` (opcional) elige QUÉ se factura: `entrega` = cada id de `order_ids` es una
 * ENTREGA (tarjeta) y se factura lo que salió de ella; `completo` = cada id es un pedido (o
 * cualquiera de sus entregas) y se factura el pedido entero o lo que falta. Sin `modo` rige
 * `completo`: el cliente anterior a SCRUM-929, que solo manda `order_ids`, no cambia de
 * comportamiento (una factura por el total del pedido).
 */
class InvoiceOrderIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_ids'   => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer'],
            'modo'        => ['sometimes', 'nullable', 'string', 'in:entrega,completo'],
            // SCRUM-1140 — pedidos del lote que se facturan a Consumidor Final por decisión de quien factura (solo en el
            // guardado; la vista previa lo ignora). Exige `admin_contab.edit.receptor_consumidor_final` (ver el controller).
            'consumidor_final_order_ids'   => ['sometimes', 'nullable', 'array'],
            'consumidor_final_order_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail): void {
                // Mismo criterio que `InvoiceService::normalizeIds()`: en `completo` cualquier entrega se resuelve a su pedido raíz.
                $normalizar = fn (mixed $id): int => $this->input('modo') === 'entrega'
                    ? (int) $id
                    : (Order::find((int) $id)?->familyRootId() ?? (int) $id);
                $lote = array_map($normalizar, is_array($this->input('order_ids')) ? $this->input('order_ids') : []);
                if (! in_array($normalizar($value), $lote, true)) {
                    $fail('El pedido a facturar como Consumidor final debe estar en el lote.');
                }
            }],
        ];
    }
}
