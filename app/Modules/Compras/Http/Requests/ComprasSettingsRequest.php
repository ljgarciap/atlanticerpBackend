<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComprasSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'low_rating_threshold' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'primary_approver_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            // SCRUM-257 (REQ-194) — hallazgo de QA: el umbral existía en la tabla paramétrica
            // pero nunca se expuso en Ajustes de Compras, sin forma de verificarlo ni ajustarlo.
            'claim_attention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'critical_days_por_aprobar' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // SCRUM-247 (REQ-184) — umbral de margen del sustituto que decide si la solicitud
            // escala a Mark. Nunca hardcodeado, ver docblock de la migración.
            'replacement_margin_threshold' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            // Lote 4 (SCRUM-246) — pesos de la fórmula de "% de similitud" (deben sumar 100, ver
            // SubstituteSimilarityServiceTest) y umbral mínimo para mostrar un candidato.
            'similarity_weight_text' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'similarity_weight_price' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'similarity_weight_margin' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'similarity_other_category_penalty' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'similarity_min_threshold' => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
