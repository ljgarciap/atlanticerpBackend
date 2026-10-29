<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\CreditableBalanceService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Batch 11 (SCRUM-559→564, REQ-482→487) — registro real de una Nota de Crédito.
 *
 * RN's cubiertas acá (ver `CreditNoteService::register()` para la lógica de aplicación real):
 * - REQ-483 RN2: `factura_origen_id` siempre obligatorio.
 * - REQ-484 RN2: `resultado` obligatorio cuando el monto efectivo supera el saldo pendiente de la
 *   factura de origen; `cuenta_bancaria_salida_id` obligatorio solo cuando `resultado=devuelto`.
 * - REQ-485 RN2: `motivo` siempre obligatorio. `monto` obligatorio salvo en Anulación completa
 *   (ahí el service lo recalcula como el valor que AÚN se puede acreditar de la factura — el total si no hay
 *   notas previas, menos las notas aplicadas o pendientes si las hay —, ignorando cualquier valor mandado).
 * - SCRUM-1116 RN3/RN4: sin nada por acreditar no hay Anulación completa, y en ningún tipo el monto puede superar lo
 *   que aún se puede acreditar (ver `CreditableBalanceService`). Se valida acá ANTES de abrir la transacción para que
 *   el 422 salga rápido; `CreditNoteService::register()` lo vuelve a comprobar bajo el bloqueo de la factura.
 * - `comprobante` siempre obligatorio, para cualquier tipo (SCRUM-865 punto 3 — reemplaza la regla
 *   condicional original de REQ-487 RN1/RN2, que solo lo exigía en Anulación completa o
 *   monto>umbral y nunca en Devolución de mercancía).
 *
 * El subtipo "Corrección de datos" (REQ-480) queda explícitamente rechazado — su submit real
 * (crear la factura nueva) es Batch 12, no este.
 */
class RegisterCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el gate real es el middleware de rol sobre toda la ruta (ver routes/admin-contab.php)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tipo = (string) $this->input('tipo');

        $facturaOrigenId = $this->input('factura_origen_id');
        $facturaOrigen = is_numeric($facturaOrigenId) ? AdminContInvoice::find((int) $facturaOrigenId) : null;

        $montoInput = is_numeric($this->input('monto')) ? (float) $this->input('monto') : 0.0;
        // SCRUM-1116 — una Anulación completa es por lo que AÚN se puede acreditar (no por el total de la factura): si no,
        // con una nota previa se calculaba un "excedente" inexistente y se pedía elegir devuelto / saldo a favor.
        $acreditable = $facturaOrigen !== null ? app(CreditableBalanceService::class)->valorAcreditableRestante($facturaOrigen) : 0.0;
        $montoEfectivo = $tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA && $facturaOrigen !== null
            ? $acreditable
            : $montoInput;

        $saldoPendiente = $facturaOrigen !== null
            ? round($facturaOrigen->monto - $facturaOrigen->saldo_aplicado, 2)
            : 0.0;
        // Un monto que ya supera lo acreditable se rechaza por `monto` (ver `validarValorAcreditable`): no tiene sentido pedir además
        // "resultado" por un excedente que nunca se va a registrar (Pre-QA SCRUM-1116, obs. 1).
        $hayExcedente = $facturaOrigen !== null
            && round($montoEfectivo - $acreditable, 2) <= 0
            && round($montoEfectivo - $saldoPendiente, 2) > 0;

        // SCRUM-865 punto 3 — antes condicional (REQ-487 RN1/RN2: solo Anulación completa o
        // monto>umbral, NUNCA en Devolución de mercancía). Ahora obligatorio siempre, para
        // cualquier tipo — regla de negocio nueva y explícita (trazabilidad: "no queda evidencia
        // de soporte de por qué se hizo cada nota"), reemplaza la condición de REQ-487 en el
        // registro. `AdminContCreditNote::requiresComprobante()` se deja intacta — sigue siendo el
        // gate defensivo de `CreditNoteService::decide()` para notas históricas que quedaron sin
        // comprobante antes de este cambio.

        return [
            'master_client_id' => ['required', 'integer'],
            'cliente' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::in([
                AdminContCreditNote::TIPO_DESCUENTO_COMERCIAL,
                AdminContCreditNote::TIPO_DEVOLUCION_MERCANCIA,
                AdminContCreditNote::TIPO_ANULACION_COMPLETA,
            ])],
            'subtipo_anulacion' => [
                Rule::requiredIf($tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA),
                'nullable',
                Rule::in([AdminContCreditNote::SUBTIPO_CANCELADO, AdminContCreditNote::SUBTIPO_CORRECCION]),
            ],
            // 'invoices' sin calificar — el search_path del tenant ya resuelve `admin_contab`
            // (mismo gotcha que `AssignBankMovementAccountRequest`: calificar acá rompe `Rule::exists`
            // fuera de un contexto de tenant activo, sin necesidad real).
            'factura_origen_id' => ['required', 'integer', Rule::exists('invoices', 'id')],
            // REQ-485 RN2 (implícito) — no aplica a Anulación completa, ahí lo calcula el service. Fase 13 (2.11): en esa anulación el campo se IGNORA
            // y ni siquiera se valida (antes un `monto` de 0 o con texto devolvía 422 por un dato que nunca se usa).
            'monto' => $tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA
                ? ['sometimes']
                : ['required', 'nullable', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string'],
            'resultado' => [
                Rule::requiredIf($hayExcedente),
                'nullable',
                Rule::in([AdminContCreditNote::RESULTADO_DEVUELTO, AdminContCreditNote::RESULTADO_SALDO_FAVOR]),
            ],
            'cuenta_bancaria_salida_id' => [
                Rule::requiredIf($hayExcedente && $this->input('resultado') === AdminContCreditNote::RESULTADO_DEVUELTO),
                'nullable', 'integer',
            ],
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'mercancia_regresa_bodega' => ['sometimes', 'boolean'],
            'devolucion_bodega_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v): void {
            $this->validarValorAcreditable($v);

            if ($this->input('subtipo_anulacion') === AdminContCreditNote::SUBTIPO_CORRECCION) {
                $v->errors()->add(
                    'subtipo_anulacion',
                    'El registro de notas de "Corrección de datos" (vista previa de factura nueva) todavía no está disponible — llega en el próximo batch.',
                );
            }
        });
    }

    /**
     * SCRUM-1116 RN3/RN4 — validación previa contra el valor que aún se puede acreditar de la factura. Misma regla y
     * mismos mensajes que `CreditNoteService::register()` (que la repite bajo el bloqueo de la factura).
     */
    private function validarValorAcreditable(ValidatorContract $v): void
    {
        $facturaId = $this->input('factura_origen_id');
        $factura = is_numeric($facturaId) ? AdminContInvoice::find((int) $facturaId) : null;
        if ($factura === null) {
            return; // la regla `exists` ya reportó el problema
        }

        $creditable = app(CreditableBalanceService::class);
        $acreditable = $creditable->valorAcreditableRestante($factura);

        if ($this->input('tipo') === AdminContCreditNote::TIPO_ANULACION_COMPLETA) {
            if ($acreditable <= 0.0) {
                $v->errors()->add('factura_origen_id', $creditable->mensajeSinSaldo($factura->numero));
            }

            return;
        }

        $monto = $this->input('monto');
        if (! is_numeric($monto) || $v->errors()->has('monto')) {
            return; // el monto ya es inválido por otra regla
        }

        if (round((float) $monto - $acreditable, 2) > 0.0) {
            $v->errors()->add('monto', $creditable->mensajeExceso(
                $factura->numero,
                (float) $monto,
                $acreditable,
                $creditable->notasPrevias((int) $factura->id),
                (float) $factura->monto,
            ));
        }
    }
}
