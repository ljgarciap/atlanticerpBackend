<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** REQ-189 Escenario 1 — comprobante obligatorio. Mismo whitelist de extensiones que documentos (extensions:, no mimes:). */
class StorePurchaseOrderPaymentRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'proof'  => [
                'required', 'file', 'max:20480',
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }
}
