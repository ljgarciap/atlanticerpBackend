<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-625: fila única (id=1) del umbral de puntos porcentuales que separa "Margen bajó" de
 * "Margen mejoró" en Pedidos — antes hubiera sido un 5 hardcodeado, configurable sin deploy por
 * decisión de Luis (mismo criterio que PricingSettings). Ver OrdersService::classifyStatus().
 *
 * @property int   $id
 * @property float $margin_drop_threshold_pts
 */
class OrdersSettings extends Model
{
    /** @var list<string> */
    protected $fillable = ['margin_drop_threshold_pts'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['margin_drop_threshold_pts' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'orders_settings';
        }

        return "{$tenant->slug}_ventas_diseno.orders_settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);
        return $settings;
    }
}
