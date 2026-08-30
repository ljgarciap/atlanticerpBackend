<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-072/SCRUM-146: upsert de la Meta de un vendedor — un único endpoint hace de
 * crear/actualizar (una fila por usuario, ver SalesGoal). "Meta Plus" es
 * opcional (no todos los vendedores la tienen definida hoy, ver el roster real
 * en el comentario de SCRUM-146).
 *
 * max: agregado 2026-07-16 (hallazgo Pre-QA) — sales_goals.goal_amount/goal_plus_amount
 * son decimal(12,2); sin este límite, un valor que desborda la columna llegaba intacto
 * a Postgres y causaba un 500 sin manejar en vez de un 422 (mismo patrón que SCRUM-81).
 */
class SalesGoalRequest extends FormRequest
{
    private const MAX_AMOUNT = 9999999999.99;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'goal_amount'      => ['required', 'numeric', 'min:0', 'max:' . self::MAX_AMOUNT],
            'goal_plus_amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:' . self::MAX_AMOUNT],
        ];
    }
}
