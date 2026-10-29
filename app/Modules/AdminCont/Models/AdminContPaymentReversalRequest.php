<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1168 F4 — solicitud de anular un cobro o de devolver dinero al cliente: la piden Contabilidad o Asistente Administrativa y la aprueba Gerencia (al aprobar se ejecuta).
 *
 * @property int         $id
 * @property string      $tipo              anulacion | devolucion
 * @property int         $master_client_id
 * @property int|null    $payment_id
 * @property string|null $source_type       cobro | abono | nota_credito (devolución)
 * @property int|null    $source_id
 * @property float|null  $monto
 * @property int|null    $bank_account_id
 * @property string|null $referencia
 * @property string|null $comprobante_key
 * @property string      $motivo
 * @property string      $estado            solicitada | ejecutada | rechazada
 * @property int|null    $requested_by
 * @property int|null    $decided_by
 * @property \Carbon\Carbon|null $decided_at
 * @property string|null $motivo_rechazo
 * @property \Carbon\Carbon|null $executed_at
 * @property \Carbon\Carbon $created_at
 */
class AdminContPaymentReversalRequest extends Model
{
    use LogsAuditTrail;

    public const TIPO_ANULACION  = 'anulacion';
    public const TIPO_DEVOLUCION = 'devolucion';
    /** Fase 13 (2.3) — reversar una compensación de saldo a favor: antes era directo; ahora lo decide el aprobador como la anulación de un cobro. */
    public const TIPO_REVERSO_COMPENSACION = 'reverso_compensacion';

    /** `source_type` de la solicitud de reverso de una compensación (`source_id` = id de la compensación). */
    public const SOURCE_COMPENSACION = 'compensacion';

    public const ESTADO_SOLICITADA = 'solicitada';
    public const ESTADO_EJECUTADA  = 'ejecutada';
    public const ESTADO_RECHAZADA  = 'rechazada';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monto' => 'float', 'decided_at' => 'datetime', 'executed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'payment_reversal_requests' : "{$tenant->slug}_admin_contab.payment_reversal_requests";
    }
}
