<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Batch 5 de Admin&Cont (REQ-467) — RN3: cliente + al menos 1 factura son el mínimo para poder
 * registrar. RN2: comentario obligatorio para Ajustes de cuenta. RN4: cuenta bancaria obligatoria
 * solo cuando de verdad va a entrar dinero real a una cuenta (nunca para Retención/Ajuste, y
 * tampoco cuando el saldo a favor cubre el 100% — ese cálculo es responsabilidad del frontend
 * mandar `monto_recibido_estimado` para que esta regla se valide sin recalcular acá el saldo a
 * favor real dos veces con posible desincronización de timing).
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $metodo = (string) $this->input('metodo_pago');
        $sinCuentaBancaria = in_array($metodo, AdminContPayment::METODOS_SIN_CUENTA_BANCARIA, true);
        $montoRecibidoSeraCero = (float) $this->input('monto_recibido_estimado', 1) <= 0;

        return [
            'master_client_id'   => ['required', 'integer'],
            'invoice_ids'        => ['required', 'array', 'min:1'],
            'invoice_ids.*'      => ['integer'],
            'aplicar_saldo_favor' => ['sometimes', 'boolean'],
            'metodo_pago'         => ['required', Rule::in(AdminContPayment::METODOS)],
            'monto_recibido_estimado' => ['required', 'numeric', 'min:0'],
            'bank_account_id'     => [
                Rule::requiredIf(! $sinCuentaBancaria && ! $montoRecibidoSeraCero),
                'nullable', 'integer',
            ],
            'numero_documento_retencion' => ['nullable', 'string', 'max:255'],
            'comentario_ajuste'           => [
                Rule::requiredIf($metodo === AdminContPayment::METODO_AJUSTE_CUENTA),
                'nullable', 'string',
            ],
            // REQ-470/471 — "Referencia / N° de transacción" del mockup, texto libre opcional.
            'referencia'  => ['nullable', 'string', 'max:255'],
            // REQ-468 RN1 — JPG/PNG/PDF, máx. 10MB, opcional (RN2).
            'comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function aplicarSaldoFavor(): bool
    {
        return (bool) $this->input('aplicar_saldo_favor', false);
    }
}
