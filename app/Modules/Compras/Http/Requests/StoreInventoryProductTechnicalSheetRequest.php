<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\VentasDiseno\Services\CatalogService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SCRUM-237 (REQ-174, hallazgo de Gerencia Test 2026-08-09) — Caso A del modal Detalle del
 * producto (modo Compras): "Cargar ficha técnica" acepta un archivo (PDF/imagen, mismo whitelist
 * que Catálogo/SCRUM-698) O, alternativamente, un link externo — exactamente uno de los dos por
 * request, nunca ambos ni ninguno.
 */
class StoreInventoryProductTechnicalSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                Rule::requiredIf(fn (): bool => ! $this->filled('link')),
                'nullable', 'file',
                'extensions:'.implode(',', CatalogService::TECHNICAL_SHEET_EXTENSIONS),
            ],
            'link' => [
                Rule::requiredIf(fn (): bool => ! $this->hasFile('file')),
                'nullable', 'string', 'url', 'max:2048',
            ],
        ];
    }
}
