<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use App\Modules\Servicios\Models\ClaimSheet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-278 RN4 — "Guardar Hoja de Reclamo" es una única acción, sin borrador intermedio (mismo
 * criterio que `SaveInspectionReportRequest`). Diagnóstico y firma del responsable son
 * obligatorios — sin ellos no hay nada que marcar como Completado.
 */
class SaveClaimSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'descripcion_problema' => ['nullable', 'string'],
            'fecha_reclamo'        => ['nullable', 'date'],
            'diagnostico'          => ['required', 'string', Rule::in(ClaimSheet::DIAGNOSTICOS)],
            'firma_responsable'    => ['required', 'string', 'max:255'],
        ];
    }
}
