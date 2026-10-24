<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-819 (RN3) — solo formatos de imagen, nunca PDF ni otro tipo de documento (a diferencia de
 * StorePurchaseOrderDocumentRequest). `extensions:`, no `mimes:` — mismo criterio ya usado en
 * StorePurchaseOrderDocumentRequest (el mime type reportado por el navegador no es confiable).
 */
class StoreProviderBankImageRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:10240',
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }
}
