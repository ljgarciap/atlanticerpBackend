<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-245 (REQ-182, ADR-SCRUM245) — búsqueda de sustituto por foto o ficha técnica. Familia
 * obligatoria (decisión de Luis 2026-07-17): acota el universo de candidatos antes de llamar a
 * Claude, tope duro de 25 en el controller. Whitelist reducida a lo que la API de Anthropic
 * soporta como content block (`extensions:`, no `mimes:` — ver feedback_laravel_mimes_vs_extensions).
 */
class SearchSubstituteCandidatesRequest extends FormRequest
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'original_catalog_product_id' => ['required', 'integer'],
            'family_id'                   => ['required', 'integer'],
            'file'                        => [
                'required', 'file', 'max:15360',
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }
}
