<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use App\Modules\Compras\Models\PurchaseOrderDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** REQ-155 — mismo whitelist de extensiones que CRM\DocumentController (extensions:, no mimes: — ver feedback_laravel_mimes_vs_extensions). */
class StorePurchaseOrderDocumentRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp',
        'zip', 'rar', '7z',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(PurchaseOrderDocument::CATEGORIES)],
            'file'     => [
                'required', 'file', 'max:512000',
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }
}
