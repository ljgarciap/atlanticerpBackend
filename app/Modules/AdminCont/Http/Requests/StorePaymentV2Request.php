<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Modules\AdminCont\Models\AdminContPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCRUM-1168 F2 — registrar un cobro con el concepto nuevo: dinero que entra (cliente + monto + método), con o sin factura. `aplicaciones`
 * es opcional: si el usuario ya decidió a qué facturas va (aplicación directa, parcial), las manda con su monto; lo que no se aplica queda
 * como saldo a favor del cliente a la espera de la compensación. Lo que depende del estado de las facturas (pendiente, bloqueos) se valida
 * en `PaymentService::registerV2()`, nunca acá, que es solo forma.
 */
class StorePaymentV2Request extends FormRequest
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

        return [
            'master_client_id'            => ['required', 'integer'],
            'monto_recibido'              => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'metodo_pago'                 => ['required', Rule::in(PaymentMethods::keys())],
            'bank_account_id'             => [Rule::requiredIf(! $sinCuentaBancaria), 'nullable', 'integer'],
            'aplicaciones'                => ['sometimes', 'array', 'max:200'],
            'aplicaciones.*.invoice_id'   => ['required', 'integer', 'distinct'],
            'aplicaciones.*.monto'        => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'numero_documento_retencion'  => ['nullable', 'string', 'max:255'],
            'comentario_ajuste'           => [Rule::requiredIf($metodo === AdminContPayment::METODO_AJUSTE_CUENTA), 'nullable', 'string'],
            'referencia'                  => ['nullable', 'string', 'max:255'],
            'comprobante'                 => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    /** @return list<array{invoice_id: int, monto: float}> */
    public function aplicaciones(): array
    {
        /** @var list<array{invoice_id: int|string, monto: int|float|string}> $rows */
        $rows = (array) $this->input('aplicaciones', []);

        return array_values(array_map(
            fn (array $r): array => ['invoice_id' => (int) $r['invoice_id'], 'monto' => round((float) $r['monto'], 2)],
            $rows,
        ));
    }
}
