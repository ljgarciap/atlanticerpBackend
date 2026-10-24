<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-635 (SCRUM-811) — payload de `PUT /api/admin-contab/settings`. `min:1` (nunca `min:0`) en
 * los 4 campos: `AdminContSettingsService::intSetting()` descarta silenciosamente cualquier valor
 * persistido `<= 0` y cae al default, mismo gotcha que `BodegaSettingsRequest`.
 */
class AdminContSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fraude_monto_desviaciones'             => ['sometimes', 'integer', 'min:1', 'max:10'],
            'fraude_monto_ventana_dias'              => ['sometimes', 'integer', 'min:1', 'max:365'],
            'fraude_aprobaciones_repetidas_n'        => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'fraude_aprobaciones_repetidas_minutos'  => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
