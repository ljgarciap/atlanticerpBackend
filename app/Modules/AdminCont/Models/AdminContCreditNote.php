<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
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
 * @property float|null    $monto_aplicado_saldo
 * @property float         $monto_usado
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
 * @property string|null   $serie
 * @property string|null   $fe_estado
 * @property string|null   $fe_detalle
 * @property string|null   $cufe
 * @property string|null   $qr_content
 * @property string|null   $xml_firmado_path
 * @property string|null   $pac_provider_usado
 * @property \Carbon\Carbon|null $fe_certificado_at
 * @property \Carbon\Carbon|null $fe_emision_at  SCRUM-1019 — fecha de emisión fijada en el primer envío al PAC
 * @property \Carbon\Carbon $created_at
 */
class AdminContCreditNote extends Model
{
    use LogsAuditTrail;

    /** Texto del rechazo del PAC: solo se expone vía los controllers de detalle, gateado a Mark/superadmin. @var list<string> */
    protected $hidden = ['fe_detalle'];

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
        'monto', 'subtotal', 'itbms', 'monto_aplicado_saldo', 'monto_usado', 'motivo', 'resultado', 'cuenta_bancaria_salida_id', 'estado',
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
            'monto_aplicado_saldo'             => 'float',
            'monto_usado'                       => 'float',
            'mercancia_regresa_bodega'          => 'boolean',
            'nueva_fecha_factura'               => 'date',
            'correccion_pendiente_aprobacion'   => 'boolean',
            'fecha'                              => 'date',
            'fecha_decision'                     => 'date',
            'created_at'                          => 'datetime',
            'fe_emision_at'                       => 'datetime',
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

    /**
     * SCRUM-903 — el saldo a favor real que esta nota genera NUNCA es `monto` a secas: cuando
     * `resultado=saldo_favor`, `applyToBalance()` primero reduce la factura de origen en
     * `monto_aplicado_saldo` (`min(monto, saldoPendiente)`), y solo el EXCEDENTE
     * (`monto - monto_aplicado_saldo`) queda disponible como saldo a favor de nivel Cliente
     * master — de ahí que `monto_usado` se reste sobre esa base, no sobre `monto`. Solo tiene
     * sentido cuando `resultado=saldo_favor` y `estado=aplicada`; para cualquier otro caso el
     * saldo a favor de esta nota es 0 por definición (nunca se le suma nada en `applyToBalance()`).
     */
    public function montoDisponibleSaldoFavor(): float
    {
        if ($this->resultado !== self::RESULTADO_SALDO_FAVOR || $this->estado !== self::ESTADO_APLICADA) {
            return 0.0;
        }

        $excedente = $this->monto - (float) ($this->monto_aplicado_saldo ?? 0.0);

        return round(max(0.0, $excedente - $this->monto_usado), 2);
    }

    public function estadoSaldoFavor(): string
    {
        $disponible = $this->montoDisponibleSaldoFavor();
        if ($disponible <= 0) {
            return 'agotado';
        }

        return $this->monto_usado > 0 ? 'usado_parcialmente' : 'disponible';
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
