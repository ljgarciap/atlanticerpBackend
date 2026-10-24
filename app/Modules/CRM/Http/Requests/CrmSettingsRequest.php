<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use App\Modules\CRM\Services\CrmSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * SCRUM-944/945 — payload de `PUT /api/crm/settings`. `min:1` (nunca `min:0`):
 * `CrmSettingsService::intSetting()` descarta silenciosamente cualquier valor persistido `<= 0`
 * y cae al default, mismo criterio que `BodegaSettingsRequest`.
 */
class CrmSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entrega_proxima_dias' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'pipeline_estancado_dias' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'cliente_contacto_obsoleto_dias' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'freshness_recent_dias' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'freshness_stale_dias' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // SCRUM-998 — el máximo de un PDF plano no puede pasar de 1.000 productos (medido: 2.000 = 45 s y 299 MB,
            // sobre el `max_execution_time` de 30 s de PHP-FPM); el volumen del ZIP, de 50 a 1.000 (un job tiene memoria
            // de sobra para una tanda de 1.000, ~170 MB).
            'catalog_pdf_max_single_products' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'catalog_pdf_max_single_photos' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'catalog_pdf_volume_size' => ['sometimes', 'integer', 'min:50', 'max:1000'],
            'catalog_pdf_export_retention_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ];
    }

    /**
     * Pre-QA SCRUM-945 hallazgo C — `freshness_recent_dias` debe ser menor que
     * `freshness_stale_dias` (si no, ningún proyecto puede ser "reciente" y "frío" con sentido).
     * Cuando llega solo uno de los dos se compara contra el valor ya persistido del otro.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $hasRecent = $this->has('freshness_recent_dias');
            $hasStale = $this->has('freshness_stale_dias');
            if (! $hasRecent && ! $hasStale) {
                return;
            }

            $settings = app(CrmSettingsService::class);
            $recent = $hasRecent ? $this->integer('freshness_recent_dias') : $settings->freshnessRecentDias();
            $stale = $hasStale ? $this->integer('freshness_stale_dias') : $settings->freshnessStaleDias();

            if ($recent >= $stale) {
                $message = trans('parametric_settings.crm.freshness_order', ['recent' => $recent, 'stale' => $stale]);
                foreach (array_filter([
                    'freshness_recent_dias' => $hasRecent,
                    'freshness_stale_dias'  => $hasStale,
                ]) as $field => $_) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}
