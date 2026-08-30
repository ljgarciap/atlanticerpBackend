<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Batch 21 — base compartida por `RejectPettyCashExpenseRequest`/`RejectPettyCashReportRequest`/
 * `ReopenPettyCashExpenseRequest` (Senior Review hallazgo #6): las 3 acciones (rechazar línea,
 * rechazar reporte completo, reabrir) piden exactamente lo mismo — un `motivo` obligatorio, sin
 * ningún otro campo — así que las 3 subclases existen solo para que el nombre de la clase en la
 * firma del método del controller documente la acción real, no para variar las reglas.
 */
abstract class PettyCashMotivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
