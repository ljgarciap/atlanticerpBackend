<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Services\PaymentMethods;
use App\Modules\AdminCont\Models\AdminContPayment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SCRUM-902 §2.2 — regla de obligatoriedad condicional (Escenario 2 del ticket): si se elige un
 * sub-cliente, el proyecto se vuelve obligatorio, con el mensaje exacto que exige el ticket. La
 * factura sigue siendo siempre opcional. Reusa el mismo catálogo de 9 métodos de pago que Cobros
 * (`AdminContPayment::METODOS`) — es el mismo enum, sin motivo para duplicarlo.
 *
 * SCRUM-955 punto 3 — `cuenta_bancaria_id` deja de ser incondicional: mismo RN4 ya aplicado en
 * `StorePaymentRequest` (Retención de impuestos/Ajuste de cuenta nunca representan un movimiento
 * bancario real).
 *
 * SCRUM-955 punto 1 — CONFLICTO DE NEGOCIO SIN RESOLVER, escalado a Luis/Arquitecto (no se
 * implementa el bloqueo duro que pide el ticket): el ticket exige impedir registrar un abono con
 * monto superior al de la factura asociada. Pero SCRUM-902 §4.5 (`AbonoService::
 * registrarUsoObligatorio()`, con test dedicado `test_excedente_de_abono_de_factura_al_cobrar_
 * genera_abono_nuevo_a_nivel_cliente`) permite ESTO A PROPÓSITO: un abono de nivel Factura mayor a
 * la factura es válido, el excedente se extrae como abono nuevo confirmado a nivel Cliente cuando
 * se cobra. Un bloqueo duro acá elimina esa función ya aprobada y con test. Necesita decisión de
 * producto antes de implementarse: ¿se retira el excedente, se vuelve opt-in con confirmación
 * explícita en UI, o el punto 1 del ticket solo aplica quedan fuera los casos ya cubiertos por
 * §4.5? Mientras tanto, el monto de la factura sí se expone al frontend (`AbonoService::
 * invoicesForSubClient()`) para que la UI pueda mostrarlo/advertir, sin bloquear.
 */
class StoreAbonoRequest extends FormRequest
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
            'master_client_id'  => ['required', 'integer'],
            'sub_client_id'     => ['nullable', 'integer'],
            'sales_project_id'  => ['nullable', 'integer'],
            'invoice_id'        => ['nullable', 'integer'],
            'monto_original'    => ['required', 'numeric', 'gt:0'],
            'metodo_pago'       => ['required', Rule::in(PaymentMethods::keys())],
            'cuenta_bancaria_id' => [Rule::requiredIf(! $sinCuentaBancaria), 'nullable', 'integer'],
            'referencia'        => ['required', 'string', 'max:255'],
            'comprobante'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->filled('sub_client_id') && ! $this->filled('sales_project_id')) {
                $v->errors()->add('sales_project_id', 'Al elegir un sub-cliente, el proyecto se vuelve obligatorio.');
            }
        });
    }
}
