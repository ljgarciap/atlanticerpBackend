<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `razon_social`, `nombre_comercial`, `moneda`, `anio_fiscal` a propósito NO están en las reglas
 * (RN1 de REQ-562, RN1 de REQ-565, RN3 de REQ-561) — nunca editables desde esta pantalla, ni
 * siquiera en modo edición. `zona_horaria` solo acepta el único valor vigente hoy (RN2 de REQ-565).
 */
class UpdateCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el gate real es el middleware EnsureIsPrimaryApprover sobre toda la ruta
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'descripcion_corta' => ['sometimes', 'string', 'max:500'],
            'sitio_web' => ['sometimes', 'nullable', 'string', 'max:255', 'url'],
            'redes_sociales' => ['sometimes', 'array'],
            'redes_sociales.*.plataforma' => ['required_with:redes_sociales', 'string', 'max:100'],
            'redes_sociales.*.url' => ['required_with:redes_sociales', 'string', 'max:255', 'url'],
            // Fase 6a — contexto regional de la empresa (vive en el tenant, ver `TenantRegion`). La zona acepta cualquier zona IANA válida.
            'pais' => ['sometimes', 'string', Rule::in(array_keys((array) config('regions.countries')))],
            'moneda' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'zona_horaria' => ['sometimes', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
        ];
    }
}
