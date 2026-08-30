<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-227 RN6 — motivo de cancelación obligatorio, texto libre. RN2 (confirmación explícita) es
 * responsabilidad del frontend (modal de confirmación), no de esta validación.
 */
class CancelTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // `required` por sí solo deja pasar un string de solo espacios — la regla de closure
            // rechaza explícitamente "obligatorio" en el sentido real de RN6, no solo "presente".
            'motivo' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) === '') {
                    $fail('El motivo de cancelación es obligatorio.');
                }
            }],
        ];
    }
}
