<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Requests;

use App\Modules\VentasDiseno\Models\SalesCommissionTier;
use App\Modules\VentasDiseno\Support\CommissionTierRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Pre-QA SCRUM-945 hallazgo A — payload de `PUT /api/ventas-diseno/sales-commission-tiers`
 * (reemplazo atómico de la tabla completa, gateado por `superadmin.all`). `orden` se acepta pero se
 * ignora: siempre se deriva del orden por `monto_minimo`. `id` es opcional — un tramo con id existente se
 * actualiza en el lugar (conserva su id), sin id se crea, y los ids ausentes se eliminan.
 */
class ReplaceSalesCommissionTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tiers'                  => ['required', 'array', 'min:1', 'max:50'],
            'tiers.*.id'             => ['sometimes', 'nullable', 'integer', 'distinct'],
            'tiers.*.monto_minimo'   => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'tiers.*.monto_maximo'   => ['present', 'nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'tiers.*.porcentaje'     => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            // Se acepta por compatibilidad con el frontend pero se IGNORA: `orden` siempre se deriva por monto_minimo.
            'tiers.*.orden'          => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Nombres legibles por fila ("porcentaje (fila 1)") en vez de la ruta cruda `tiers.0.porcentaje`.
     * Solo cambia el texto del mensaje: las claves de error (`tiers.N.<campo>`) son las mismas.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['tiers' => trans('parametric_settings.attributes.tiers')];

        $rows = $this->input('tiers');
        $count = is_array($rows) ? count($rows) : 0;
        for ($i = 0; $i < $count; $i++) {
            foreach (['id', 'monto_minimo', 'monto_maximo', 'porcentaje', 'orden'] as $field) {
                $attributes["tiers.{$i}.{$field}"] = trans('parametric_settings.attributes.row_template', [
                    'field' => trans("parametric_settings.attributes.{$field}"),
                    'row'   => $i + 1,
                ]);
            }
        }

        return $attributes;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array{id?: int|null, monto_minimo: mixed, monto_maximo: mixed, porcentaje: mixed}> $tiers */
            $tiers = $this->validated()['tiers'];

            $ids = array_values(array_filter(array_column($tiers, 'id'), static fn ($id): bool => $id !== null));
            if ($ids !== []) {
                $existing = SalesCommissionTier::query()->whereIn('id', $ids)->pluck('id')->all();
                foreach (array_diff($ids, $existing) as $missing) {
                    $validator->errors()->add('tiers', trans('parametric_settings.tiers.unknown_id', ['id' => $missing]));
                }
            }

            foreach (CommissionTierRules::fullSetErrors($tiers) as $message) {
                $validator->errors()->add('tiers', $message);
            }
        }];
    }
}
