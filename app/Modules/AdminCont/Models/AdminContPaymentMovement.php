<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1168 F1 — un movimiento del libro de cobros (ver la migración de creación). Append-only: no se edita ni se borra, un reverso es otro
 * movimiento. Sin `updated_at`. Tampoco usa `LogsAuditTrail`: el libro YA es la traza; auditarlo duplicaría cada fila.
 *
 * @property int         $id
 * @property int         $master_client_id
 * @property string      $tipo
 * @property int|null    $payment_id
 * @property int|null    $invoice_id
 * @property float       $monto
 * @property float       $delta_disponible
 * @property float       $delta_factura
 * @property string      $origen
 * @property int|null    $compensation_id
 * @property int|null    $reverses_movement_id
 * @property int|null    $actor_id
 * @property int|null    $approved_by
 * @property string|null $motivo
 * @property \Carbon\Carbon $fecha_movimiento
 */
class AdminContPaymentMovement extends Model
{
    public const UPDATED_AT = null;

    public const TIPO_INGRESO         = 'ingreso';
    public const TIPO_APLICACION      = 'aplicacion';
    public const TIPO_DESAPLICACION   = 'desaplicacion';
    public const TIPO_ANULACION       = 'anulacion';
    public const TIPO_DEVOLUCION      = 'devolucion';
    public const TIPO_HISTORICO       = 'historico';
    public const TIPO_RECLASIFICACION = 'reclasificacion';

    public const ORIGEN_DIRECTA   = 'directa';
    public const ORIGEN_CRUCE     = 'cruce';
    public const ORIGEN_MIGRACION = 'migracion';
    public const ORIGEN_SISTEMA   = 'sistema';

    /** @var list<string> */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('payment_movements es append-only: un movimiento no se edita, se reversa con otro.'));
        static::deleting(static fn () => throw new \LogicException('payment_movements es append-only: un movimiento no se borra, se reversa con otro.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto'            => 'float',
            'delta_disponible' => 'float',
            'delta_factura'    => 'float',
            'fecha_movimiento' => 'datetime',
            'created_at'       => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payment_movements';
        }

        return "{$tenant->slug}_admin_contab.payment_movements";
    }
}
