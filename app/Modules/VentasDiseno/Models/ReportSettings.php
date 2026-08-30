<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-075/076: fila única (id=1) de parámetros configurables de Reportes —
 * umbral+meses de la alerta de "Top de vendedores" y probabilidades de
 * "Forecast". Ver ReportSettings::current().
 *
 * @property int   $id
 * @property float $low_sales_threshold
 * @property int   $low_sales_months
 * @property float $quote_probability_percent
 * @property float $proposal_probability_percent
 */
class ReportSettings extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'low_sales_threshold', 'low_sales_months',
        'quote_probability_percent', 'proposal_probability_percent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'low_sales_threshold'          => 'float',
            'low_sales_months'             => 'integer',
            'quote_probability_percent'    => 'float',
            'proposal_probability_percent' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'report_settings';
        }

        return "{$tenant->slug}_ventas_diseno.report_settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);
        return $settings;
    }
}
