<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Services\PaymentMethods;
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
 *
 * SCRUM-902 §4.4 — `aplicar_saldo_favor` (checkbox todo-o-nada) se retiró: `monto_saldo_cliente_
 * aplicado` es el monto que el usuario decidió aplicar de su saldo Cliente master, validado de
 * verdad contra el disponible real en `PaymentService::register()` (nunca acá, que es solo forma).
 * La aplicación de abonos nivel Factura/Proyecto es obligatoria y no pasa por ningún campo de
 * este request — la resuelve el backend solo.
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
        $sinCuentaBancaria = PaymentMethods::sinCuentaBancaria($metodo);
        $montoRecibidoSeraCero = (float) $this->input('monto_recibido_estimado', 1) <= 0;

        return [
            'master_client_id'   => ['required', 'integer'],
            'invoice_ids'        => ['required', 'array', 'min:1'],
            'invoice_ids.*'      => ['integer'],
            'monto_saldo_cliente_aplicado' => ['sometimes', 'numeric', 'min:0'],
            'metodo_pago'         => ['required', Rule::in(PaymentMethods::keys())],
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

    public function montoSaldoClienteAplicado(): float
    {
        return (float) $this->input('monto_saldo_cliente_aplicado', 0);
    }
}
