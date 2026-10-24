<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SCRUM-1114 — validación de `PUT /api/legacy/migracion/stock-sync`. `baseline_inicializada_at` NO es
 * editable desde acá: solo `legacy:init-stock-baseline --apply` la marca (una base falsa duplicaría stock).
 */
final class StockSyncSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el gate real es el middleware `role:superadmin` de la ruta
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'activo'                        => ['sometimes', 'boolean'],
            // Topes de validación a propósito: un umbral sin techo real dejaría el circuit breaker sin efecto
            // (revisión de seguridad S-02). Un valor mayor se cambia con un deploy y su Senior Review.
            'max_productos_con_delta_pct'   => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'max_unidades_abs_por_producto' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'max_unidades_abs_total'        => ['sometimes', 'integer', 'min:1', 'max:500000'],
            'min_filas_icg_pct'             => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'horas_aviso_apagado'           => ['sometimes', 'integer', 'min:1', 'max:720'],
            'correos_alerta'                => ['sometimes', 'array', 'max:20'],
            // El dominio debe llevar punto (`a@x` pasa `email:rfc` pero hace fallar el envío): misma regla que el panel.
            'correos_alerta.*'              => ['required', 'email:rfc', 'regex:/^[^@\s]+@[^@\s]+\.[^@\s]+$/', 'max:190', 'distinct:ignore_case', $this->dominioPermitido()],
        ];
    }

    /**
     * Nombres legibles en los mensajes 422 (en vez de "max productos con delta pct").
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'activo'                        => 'interruptor de corridas programadas',
            'max_productos_con_delta_pct'   => '% máximo de productos con delta',
            'max_unidades_abs_por_producto' => 'máximo de unidades por producto',
            'max_unidades_abs_total'        => 'máximo de unidades absolutas en total',
            'min_filas_icg_pct'             => '% mínimo de filas de ICG',
            'horas_aviso_apagado'           => 'horas apagado antes de avisar',
            'correos_alerta'                => 'correos de alerta',
            'correos_alerta.*'              => 'correo de alerta',
        ];
    }

    /**
     * Un campo que no se puede editar (`baseline_inicializada_at`: solo `legacy:init-stock-baseline --apply` la
     * marca) o desconocido responde 422 en vez de ignorarse en silencio, para no engañar a un cliente de la API.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules()), ['correos_alerta.*']) as $campo) {
                $v->errors()->add((string) $campo, "El campo {$campo} no se puede editar desde acá.");
            }
        });
    }

    /** S-03 — si hay lista de dominios permitidos (config/legacy.php), el correo adicional debe pertenecer a uno. */
    private function dominioPermitido(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            /** @var list<string> $permitidos */
            $permitidos = (array) config('legacy.alert_allowed_domains', []);
            if ($permitidos === []) {
                return;
            }
            $dominio = strtolower((string) substr(strrchr((string) $value, '@') ?: '', 1));
            if (! in_array($dominio, $permitidos, true)) {
                $fail('El dominio del correo no está permitido para los avisos de migración.');
            }
        };
    }
}
