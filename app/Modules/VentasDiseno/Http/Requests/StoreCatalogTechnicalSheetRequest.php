<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Services\CatalogService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-618 RN1 — formatos PNG/JPG/PDF, SIN límite de tamaño artificial de la app (a propósito no
 * hay regla `max:`, el límite real es el de PHP/Nginx del entorno). Whitelist por `extensions:`,
 * no `mimes:` — mismo criterio que `StorePurchaseOrderDocumentRequest`/`CRM\DocumentController`
 * (ver feedback_laravel_mimes_vs_extensions en memoria del proyecto).
 */
class StoreCatalogTechnicalSheetRequest extends FormRequest
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
                'required', 'file',
                'extensions:'.implode(',', CatalogService::TECHNICAL_SHEET_EXTENSIONS),
            ],
        ];
    }
}
