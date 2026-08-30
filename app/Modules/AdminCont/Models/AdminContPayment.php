<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Batch 5 de Admin&Cont (SCRUM-539→544, REQ-462→467) — ver docblock de la migración de creación.
 * `numero_recibo`/`referencia` agregados en Batch 6 (SCRUM-545→548, REQ-468→471) — ver docblock de
 * `add_batch6_fields_to_admin_contab_payments_table`. Batch 7 (SCRUM-549→552, REQ-472→475) agrega
 * comprobante/recibo formal (sin columna propia, ver `attachment()`), apertura automática desde
 * Facturación (ya cubierta por `master_client_id`/`accion` en la query string, sin cambio de
 * esquema) y confirmación manual (`confirmation()`).
 *
 * @property int             $id
 * @property string|null     $numero_recibo
 * @property int             $master_client_id
 * @property float           $monto_recibido
 * @property float           $saldo_favor_aplicado
 * @property string          $metodo_pago
 * @property string|null     $referencia
 * @property int|null        $bank_account_id
 * @property string|null     $numero_documento_retencion
 * @property string|null     $comentario_ajuste
 * @property string          $estado
 * @property int|null        $created_by
 * @property \Carbon\Carbon  $created_at
 */
class AdminContPayment extends Model
{
    public const METODO_TRANSFERENCIA       = 'transferencia';
    public const METODO_CHEQUE              = 'cheque';
    public const METODO_EFECTIVO            = 'efectivo';
    public const METODO_TARJETA             = 'tarjeta';
    public const METODO_DEPOSITO            = 'deposito';
    public const METODO_YAPPY               = 'yappy';
    public const METODO_LINK_PAGO           = 'link_pago';
    public const METODO_RETENCION_IMPUESTOS = 'retencion_impuestos';
    public const METODO_AJUSTE_CUENTA       = 'ajuste_cuenta';

    /** @var list<string> */
    public const METODOS = [
        self::METODO_TRANSFERENCIA, self::METODO_CHEQUE, self::METODO_EFECTIVO, self::METODO_TARJETA,
        self::METODO_DEPOSITO, self::METODO_YAPPY, self::METODO_LINK_PAGO,
        self::METODO_RETENCION_IMPUESTOS, self::METODO_AJUSTE_CUENTA,
    ];

    /** REQ-466 RN4 — estos 2 métodos nunca representan dinero entrando a una cuenta bancaria real. */
    public const METODOS_SIN_CUENTA_BANCARIA = [self::METODO_RETENCION_IMPUESTOS, self::METODO_AJUSTE_CUENTA];

    /**
     * REQ-473 RN1 — etiqueta en español para el recibo formal (PDF, sin i18n en backend). Mismos
     * textos que `cobros.metodos.*` en el frontend (`adminContab.json`) — mantener sincronizados.
     * @var array<string, string>
     */
    public const METODO_LABELS = [
        self::METODO_TRANSFERENCIA       => 'Transferencia',
        self::METODO_CHEQUE              => 'Cheque',
        self::METODO_EFECTIVO            => 'Efectivo',
        self::METODO_TARJETA             => 'Tarjeta',
        self::METODO_DEPOSITO            => 'Depósito bancario',
        self::METODO_YAPPY               => 'Yappy',
        self::METODO_LINK_PAGO           => 'Link de pago',
        self::METODO_RETENCION_IMPUESTOS => 'Retención de impuestos',
        self::METODO_AJUSTE_CUENTA       => 'Ajustes de cuenta',
    ];

    /**
     * REQ-469 RN2/RN3 — Efectivo se confirma de inmediato (se cuenta físico en el momento);
     * Retención de impuestos y Ajustes de cuenta tampoco pasan por "Esperando confirmación" porque
     * no representan dinero entrando a una cuenta bancaria. Los 6 métodos restantes (Transferencia,
     * Cheque, Depósito bancario, Tarjeta, Yappy, Link de pago) siempre arrancan
     * `esperando_confirmacion` — reemplaza la regla simplificada de Batch 5 (solo `link_pago`),
     * que era un placeholder a propósito hasta que este batch trajera el detalle real (ver docblock
     * de la migración de creación de `payments`).
     */
    public const METODOS_CONFIRMACION_INMEDIATA = [
        self::METODO_EFECTIVO, self::METODO_RETENCION_IMPUESTOS, self::METODO_AJUSTE_CUENTA,
    ];

    public const ESTADO_CONFIRMADO             = 'confirmado';
    public const ESTADO_ESPERANDO_CONFIRMACION = 'esperando_confirmacion';

    /** @var list<string> */
    protected $fillable = [
        'numero_recibo', 'master_client_id', 'monto_recibido', 'saldo_favor_aplicado', 'metodo_pago',
        'referencia', 'bank_account_id', 'numero_documento_retencion', 'comentario_ajuste', 'estado',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto_recibido'       => 'float',
            'saldo_favor_aplicado' => 'float',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payments';
        }

        return "{$tenant->slug}_admin_contab.payments";
    }

    /** @return BelongsTo<AdminContBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(AdminContBankAccount::class, 'bank_account_id');
    }

    /** @return HasMany<AdminContPaymentInvoice, $this> */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(AdminContPaymentInvoice::class, 'payment_id');
    }

    /**
     * REQ-468/471 — a lo sumo 1 comprobante por cobro.
     * @return HasOne<AdminContPaymentAttachment, $this>
     */
    public function attachment(): HasOne
    {
        return $this->hasOne(AdminContPaymentAttachment::class, 'payment_id');
    }

    /**
     * REQ-475 — a lo sumo 1 registro de confirmación manual por cobro (RN4).
     * @return HasOne<AdminContPaymentConfirmation, $this>
     */
    public function confirmation(): HasOne
    {
        return $this->hasOne(AdminContPaymentConfirmation::class, 'payment_id');
    }

    /**
     * Batch 19 (REQ-525) — constancia fiscal de retención entregada por el cliente, distinta del
     * comprobante de pago (`attachment()`). A lo sumo 1 por cobro, solo aplica cuando
     * `metodo_pago = retencion_impuestos`.
     * @return HasOne<AdminContPaymentRetentionAttachment, $this>
     */
    public function retentionAttachment(): HasOne
    {
        return $this->hasOne(AdminContPaymentRetentionAttachment::class, 'payment_id');
    }
}
