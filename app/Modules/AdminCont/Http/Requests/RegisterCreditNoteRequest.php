<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
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
 *   (ahí el service lo recalcula del total de la factura, ignorando cualquier valor mandado).
 * - REQ-487 RN1/RN2: `comprobante` obligatorio en Anulación completa o monto>umbral — EXCEPTO en
 *   Devolución de mercancía, donde nunca es obligatorio (RN2 pisa a RN1).
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
        $montoEfectivo = $tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA && $facturaOrigen !== null
            ? $facturaOrigen->monto
            : $montoInput;

        $saldoPendiente = $facturaOrigen !== null
            ? round($facturaOrigen->monto - $facturaOrigen->saldo_aplicado, 2)
            : 0.0;
        $hayExcedente = $facturaOrigen !== null && round($montoEfectivo - $saldoPendiente, 2) > 0;

        $threshold = (float) AdminContFiscalSettings::current()->primary_approval_threshold;

        // REQ-487 RN1/RN2 — la devolución nunca lo exige, sin importar tipo/monto. Predicado
        // compartido con `CreditNoteService::decide()` (Batch 13, RN2 de REQ-494) — ver docblock
        // del método en el modelo, nunca replicar esta regla a mano en otro lado.
        $requiereComprobante = AdminContCreditNote::requiresComprobante($tipo, $montoEfectivo, $threshold);

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
            // REQ-485 RN2 (implícito) — no aplica a Anulación completa, ahí lo calcula el service.
            'monto' => [
                Rule::requiredIf($tipo !== AdminContCreditNote::TIPO_ANULACION_COMPLETA),
                'nullable', 'numeric', 'min:0.01',
            ],
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
            'comprobante' => [
                Rule::requiredIf($requiereComprobante),
                'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240',
            ],
            'mercancia_regresa_bodega' => ['sometimes', 'boolean'],
            'devolucion_bodega_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $v): void {
            if ($this->input('subtipo_anulacion') === AdminContCreditNote::SUBTIPO_CORRECCION) {
                $v->errors()->add(
                    'subtipo_anulacion',
                    'El registro de notas de "Corrección de datos" (vista previa de factura nueva) todavía no está disponible — llega en el próximo batch.',
                );
            }
        });
    }
}
