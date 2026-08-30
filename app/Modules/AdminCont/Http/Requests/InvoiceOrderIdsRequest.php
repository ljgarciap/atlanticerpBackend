<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 2 de Facturación — preview/store aceptan 1..N entregas a la vez ("Guardar factura(s)", REQ-441 Escenario 3). */
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
            'order_ids'          => ['required', 'array', 'min:1'],
            'order_ids.*'        => ['integer'],
            // Batch 3 (REQ-442) — default true: por defecto se ofrece/aplica saldo a favor si el
            // cliente lo tiene (RN1); el caller lo pone en false para descartarlo explícitamente.
            'aplicar_saldo_favor' => ['sometimes', 'boolean'],
        ];
    }

    public function aplicarSaldoFavor(): bool
    {
        return (bool) $this->input('aplicar_saldo_favor', true);
    }
}
