<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1114 — fila única (id=1) de parámetros de `legacy:sync-incremental-stock`. Mismo patrón que
 * `ComprasSettings`: sembrada por la migración, siempre existe. Con `LogsAuditTrail` cada cambio de
 * un umbral o del interruptor queda en `audit_logs` (quién apagó/encendió el sync y cuándo).
 *
 * @property int                      $id
 * @property bool                     $activo
 * @property \Carbon\Carbon|null      $baseline_inicializada_at
 * @property float                    $max_productos_con_delta_pct
 * @property int                      $max_unidades_abs_por_producto
 * @property int                      $max_unidades_abs_total
 * @property float                    $min_filas_icg_pct
 * @property int                      $horas_aviso_apagado
 * @property list<string>             $correos_alerta
 */
class StockSyncSettings extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'activo', 'baseline_inicializada_at', 'max_productos_con_delta_pct',
        'max_unidades_abs_por_producto', 'max_unidades_abs_total', 'min_filas_icg_pct', 'horas_aviso_apagado',
        'correos_alerta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo'                        => 'boolean',
            'baseline_inicializada_at'      => 'datetime',
            'max_productos_con_delta_pct'   => 'float',
            'max_unidades_abs_por_producto' => 'integer',
            'max_unidades_abs_total'        => 'integer',
            'min_filas_icg_pct'             => 'float',
            'horas_aviso_apagado'           => 'integer',
            'correos_alerta'                => 'array',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'stock_sync_settings';
        }

        return "{$tenant->slug}_legacy.stock_sync_settings";
    }

    /** La fila única de configuración — sembrada por la migración, siempre existe. */
    public static function current(): self
    {
        /** @var self $settings */
        $settings = self::findOrFail(1);

        return $settings;
    }
}
