<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-033: fila única (id=1) de los porcentajes/divisores de las fórmulas de
 * Tipo de Precio — antes constantes PHP en QuoteItem, ahora configurables sin
 * deploy (Luis, 2026-07-12). Ver PricingSettings::current().
 *
 * `min_margin_percent` (REQ-040/045, agregado 2026-07-15): margen mínimo que
 * protege QuoteItem::violatesMinMargin() — nunca se expone el valor exacto al
 * usuario final cuando se revierte un cambio (ver QuoteItemController/
 * QuoteController), solo a quien tenga `ventas_diseno.pricing.configure`.
 *
 * @property int   $id
 * @property float $project_discount_percent
 * @property float $special_additional_discount_percent
 * @property float $partner_divisor
 * @property float $premium_divisor
 * @property float $min_margin_percent
 */
class PricingSettings extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'project_discount_percent', 'special_additional_discount_percent',
        'partner_divisor', 'premium_divisor', 'min_margin_percent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'project_discount_percent'            => 'float',
            'special_additional_discount_percent' => 'float',
            'partner_divisor'                     => 'float',
            'premium_divisor'                      => 'float',
            'min_margin_percent'                   => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pricing_settings';
        }

        return "{$tenant->slug}_ventas_diseno.pricing_settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);
        return $settings;
    }
}
