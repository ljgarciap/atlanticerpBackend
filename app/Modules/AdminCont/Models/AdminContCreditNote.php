<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 10 (SCRUM-553→558, REQ-476→481) — ver docblock de la migración de creación para el detalle
 * completo de cada columna. Tabla sin escritor real todavía (ver ese mismo docblock) — "registrar
 * nota" es Batch 11.
 *
 * @property int         $id
 * @property string       $numero
 * @property int           $master_client_id
 * @property string        $cliente
 * @property string        $tipo
 * @property string|null   $subtipo_anulacion
 * @property int           $factura_origen_id
 * @property float         $monto
 * @property float         $subtotal
 * @property float         $itbms
 * @property string        $motivo
 * @property string|null   $resultado
 * @property int|null      $cuenta_bancaria_salida_id
 * @property string        $estado
 * @property bool|null     $mercancia_regresa_bodega
 * @property string|null   $motivo_correccion
 * @property int|null      $nuevo_tratamiento_itbms_rate_id
 * @property \Carbon\Carbon|null $nueva_fecha_factura
 * @property int|null      $factura_nueva_id
 * @property bool          $correccion_pendiente_aprobacion
 * @property string|null   $comprobante_url
 * @property int           $registrado_por
 * @property \Carbon\Carbon $fecha
 * @property int|null      $aprobado_por
 * @property \Carbon\Carbon|null $fecha_decision
 * @property string|null   $motivo_rechazo
 * @property int|null      $devolucion_bodega_id
 * @property \Carbon\Carbon $created_at
 */
class AdminContCreditNote extends Model
{
    public const TIPO_DESCUENTO_COMERCIAL  = 'descuento_comercial';

    public const TIPO_DEVOLUCION_MERCANCIA = 'devolucion_mercancia';

    public const TIPO_ANULACION_COMPLETA   = 'anulacion_completa';

    public const SUBTIPO_CANCELADO  = 'cancelado';

    public const SUBTIPO_CORRECCION = 'correccion';

    public const MOTIVO_CORRECCION_ITBMS  = 'itbms';

    public const MOTIVO_CORRECCION_FECHA  = 'fecha';

    public const MOTIVO_CORRECCION_AMBOS  = 'ambos';

    public const ESTADO_APLICADA            = 'aplicada';

    public const ESTADO_PENDIENTE_APROBACION = 'pendiente_aprobacion';

    public const ESTADO_RECHAZADA            = 'rechazada';

    public const RESULTADO_APLICADO_SALDO = 'aplicado_saldo';

    public const RESULTADO_DEVUELTO       = 'devuelto';

    public const RESULTADO_SALDO_FAVOR    = 'saldo_favor';

    /** @var list<string> */
    protected $fillable = [
        'numero', 'master_client_id', 'cliente', 'tipo', 'subtipo_anulacion', 'factura_origen_id',
        'monto', 'subtotal', 'itbms', 'motivo', 'resultado', 'cuenta_bancaria_salida_id', 'estado',
        'mercancia_regresa_bodega', 'motivo_correccion', 'nuevo_tratamiento_itbms_rate_id',
        'nueva_fecha_factura', 'factura_nueva_id', 'correccion_pendiente_aprobacion',
        'comprobante_url', 'registrado_por', 'fecha', 'aprobado_por', 'fecha_decision',
        'motivo_rechazo', 'devolucion_bodega_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto'                           => 'float',
            'subtotal'                         => 'float',
            'itbms'                             => 'float',
            'mercancia_regresa_bodega'          => 'boolean',
            'nueva_fecha_factura'               => 'date',
            'correccion_pendiente_aprobacion'   => 'boolean',
            'fecha'                              => 'date',
            'fecha_decision'                     => 'date',
            'created_at'                          => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'credit_notes';
        }

        return "{$tenant->slug}_admin_contab.credit_notes";
    }

    /** @return BelongsTo<AdminContInvoice, $this> */
    public function facturaOrigen(): BelongsTo
    {
        return $this->belongsTo(AdminContInvoice::class, 'factura_origen_id');
    }

    /** @return BelongsTo<AdminContInvoice, $this> */
    public function facturaNueva(): BelongsTo
    {
        return $this->belongsTo(AdminContInvoice::class, 'factura_nueva_id');
    }

    /** @return BelongsTo<AdminContItbmsRate, $this> */
    public function nuevoTratamientoItbms(): BelongsTo
    {
        return $this->belongsTo(AdminContItbmsRate::class, 'nuevo_tratamiento_itbms_rate_id');
    }

    /** @return BelongsTo<AdminContBankAccount, $this> */
    public function cuentaBancariaSalida(): BelongsTo
    {
        return $this->belongsTo(AdminContBankAccount::class, 'cuenta_bancaria_salida_id');
    }

    /**
     * Batch 12 (REQ-492/493)
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /**
     * Batch 12 (REQ-493)
     * @return BelongsTo<User, $this>
     */
    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /**
     * Batch 13 (REQ-487/494) — predicado único de "esta nota exige comprobante de soporte",
     * extraído de `RegisterCreditNoteRequest::rules()` (donde vivía duplicado inline) para que
     * `CreditNoteService::decide()` (RN2 de REQ-494, bloquea la aprobación sin comprobante) use
     * exactamente la misma regla que el registro original — nunca la reescribe a mano. La
     * devolución de mercancía NUNCA lo exige, sin importar tipo/monto (REQ-487 RN1/RN2, esa
     * excepción pisa a la de Anulación completa/umbral).
     */
    public static function requiresComprobante(string $tipo, float $monto, float $threshold): bool
    {
        return $tipo !== self::TIPO_DEVOLUCION_MERCANCIA
            && ($tipo === self::TIPO_ANULACION_COMPLETA || $monto > $threshold);
    }
}
