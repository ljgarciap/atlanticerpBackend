<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use App\Modules\AdminCont\Models\AdminContCreditNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Batch 12 (SCRUM-565→570, REQ-489) — revisión previa de "Corrección de datos" (RN1: solo
 * lectura, nada se persiste). `RegisterCreditNoteCorreccionRequest` extiende esta clase y agrega
 * la exigencia de `comprobante` para el registro real.
 */
class PreviewCreditNoteCorreccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el gate real es el middleware de rol sobre toda la ruta (ver routes/admin-contab.php)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $motivo       = (string) $this->input('motivo_correccion');
        $incluyeItbms = in_array($motivo, [AdminContCreditNote::MOTIVO_CORRECCION_ITBMS, AdminContCreditNote::MOTIVO_CORRECCION_AMBOS], true);
        $incluyeFecha = in_array($motivo, [AdminContCreditNote::MOTIVO_CORRECCION_FECHA, AdminContCreditNote::MOTIVO_CORRECCION_AMBOS], true);

        return [
            'master_client_id'   => ['required', 'integer'],
            // 'invoices'/'itbms_rates' sin calificar — mismo gotcha ya documentado en
            // RegisterCreditNoteRequest: el search_path del tenant ya resuelve `admin_contab`.
            'factura_origen_id' => ['required', 'integer', Rule::exists('invoices', 'id')],
            'motivo_correccion' => ['required', Rule::in([
                AdminContCreditNote::MOTIVO_CORRECCION_ITBMS,
                AdminContCreditNote::MOTIVO_CORRECCION_FECHA,
                AdminContCreditNote::MOTIVO_CORRECCION_AMBOS,
            ])],
            // RN1 REQ-488 — obligatorio SOLO si el motivo corrige el ITBMS (RN4: si corrige fecha,
            // la tasa se conserva de la factura original, ver `CreditNoteService::correccionContext()`).
            'nuevo_tratamiento_itbms_rate_id' => [
                Rule::requiredIf($incluyeItbms), 'nullable', 'integer', Rule::exists('itbms_rates', 'id'),
            ],
            // RN4 REQ-488 — obligatoria (aunque no editable en UI) si el motivo corrige la fecha.
            'nueva_fecha' => [Rule::requiredIf($incluyeFecha), 'nullable', 'date'],
            'motivo'      => ['required', 'string'],
        ];
    }
}
