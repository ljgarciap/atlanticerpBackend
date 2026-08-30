<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Batch 12 (SCRUM-565→570, REQ-488/490) — registro real de "Corrección de datos", mismas reglas
 * que la revisión previa (`PreviewCreditNoteCorreccionRequest`) más `comprobante` obligatorio —
 * REQ-487 RN1: "Anulación completa" (correccion es su subtipo) siempre lo exige, sin importar el
 * monto, mismo criterio que `RegisterCreditNoteRequest`.
 */
class RegisterCreditNoteCorreccionRequest extends PreviewCreditNoteCorreccionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'comprobante' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ];
    }
}
