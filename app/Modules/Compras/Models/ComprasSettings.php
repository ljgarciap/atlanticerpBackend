<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila única (id=1) de parámetros configurables del módulo Compras — `low_rating_threshold`
 * (REQ-121/122) y `primary_approver_user_id` (SCRUM-206: quién es "Gerencia" para el gate de
 * aprobación de REQ-143, configurable en vez de hardcodeado por email). Mismo patrón que
 * App\Modules\VentasDiseno\Models\ReportSettings::current().
 *
 * `zona_libre_provider_id` (SCRUM-433, Bloque B3) — el `Provider` fijo "Zona Libre de Colón" al
 * que Bodega genera órdenes directamente, sembrado por `ZonaLibreProviderSeeder`. Mismo criterio
 * que `primary_approver_user_id`: nunca hardcodeado, siempre configurable.
 *
 * `similarity_weight_*`/`similarity_other_category_penalty`/`similarity_min_threshold` (Lote 4,
 * SCRUM-246, 2026-08-17) — pesos y umbral de la fórmula de "% de similitud" del método "Buscar en
 * catálogo" de Comparación de Referencias, ver `SubstituteSimilarityService`.
 *
 * @property int $id
 * @property float $low_rating_threshold
 * @property int|null $primary_approver_user_id
 * @property int $claim_attention_days
 * @property int $critical_days_por_aprobar
 * @property float $replacement_margin_threshold
 * @property int|null $zona_libre_provider_id
 * @property float $similarity_weight_text
 * @property float $similarity_weight_price
 * @property float $similarity_weight_margin
 * @property float $similarity_other_category_penalty
 * @property float $similarity_min_threshold
 */
class ComprasSettings extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'low_rating_threshold', 'primary_approver_user_id', 'claim_attention_days', 'critical_days_por_aprobar',
        'replacement_margin_threshold', 'zona_libre_provider_id',
        'similarity_weight_text', 'similarity_weight_price', 'similarity_weight_margin',
        'similarity_other_category_penalty', 'similarity_min_threshold',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'low_rating_threshold' => 'float',
            'primary_approver_user_id' => 'integer',
            'claim_attention_days' => 'integer',
            'critical_days_por_aprobar' => 'integer',
            'replacement_margin_threshold' => 'float',
            'zona_libre_provider_id' => 'integer',
            'similarity_weight_text' => 'float',
            'similarity_weight_price' => 'float',
            'similarity_weight_margin' => 'float',
            'similarity_other_category_penalty' => 'float',
            'similarity_min_threshold' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'settings';
        }

        return "{$tenant->slug}_compras.settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);

        return $settings;
    }
}
