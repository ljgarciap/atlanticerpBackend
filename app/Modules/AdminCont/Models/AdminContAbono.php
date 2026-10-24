<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-902 (Registrar Abono) — reemplaza a `AdminContCustomerCreditBalance`/`CreditBalanceService`
 * (retirados en el mismo batch, ver docblock de la migración de drop de `customer_credit_balances`)
 * como fuente de verdad de saldo a favor por abono. Ver `App\Modules\AdminCont\Services\
 * AbonoService` para la lógica de integración con Facturación/Cobros (ticket sección 4).
 *
 * @property int         $id
 * @property string|null $numero_recibo
 * @property int         $master_client_id
 * @property int|null    $sub_client_id
 * @property int|null    $sales_project_id
 * @property int|null    $invoice_id
 * @property float       $monto_original
 * @property float       $monto_usado
 * @property string      $metodo_pago
 * @property int|null    $cuenta_bancaria_id
 * @property string      $referencia
 * @property string|null $comprobante_url
 * @property string      $estado
 * @property int         $registrado_por
 * @property int|null    $confirmado_por
 * @property \Carbon\Carbon $fecha_registro
 * @property \Carbon\Carbon|null $fecha_confirmacion
 * @property int|null    $abono_origen_id
 * @property \Carbon\Carbon $created_at
 */
class AdminContAbono extends Model
{
    use LogsAuditTrail;

    public const ESTADO_POR_CONFIRMAR = 'por_confirmar';
    public const ESTADO_CONFIRMADO    = 'confirmado';

    public const NIVEL_CLIENTE  = 'cliente';
    public const NIVEL_PROYECTO = 'proyecto';
    public const NIVEL_FACTURA  = 'factura';

    /** @var list<string> */
    protected $fillable = [
        'numero_recibo', 'master_client_id', 'sub_client_id', 'sales_project_id', 'invoice_id',
        'monto_original', 'monto_usado', 'metodo_pago', 'cuenta_bancaria_id', 'referencia',
        'comprobante_url', 'estado', 'registrado_por', 'confirmado_por', 'fecha_registro',
        'fecha_confirmacion', 'abono_origen_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto_original'      => 'float',
            'monto_usado'         => 'float',
            'fecha_registro'      => 'datetime',
            'fecha_confirmacion'  => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'abonos';
        }

        return "{$tenant->slug}_admin_contab.abonos";
    }

    /**
     * Nunca persistido — se deriva de cuáles de `invoice_id`/`sales_project_id` están llenos
     * (ticket sección 2.2). Esto también resuelve la re-asociación de la sección 4.3 sin tocar
     * ningún enum guardado: en cuanto `InvoiceService` setea `invoice_id`, el nivel pasa a
     * mostrarse como "Factura" solo.
     */
    public function nivelAsociacion(): string
    {
        if ($this->invoice_id !== null) {
            return self::NIVEL_FACTURA;
        }
        if ($this->sales_project_id !== null) {
            return self::NIVEL_PROYECTO;
        }

        return self::NIVEL_CLIENTE;
    }

    public function montoDisponible(): float
    {
        return round($this->monto_original - $this->monto_usado, 2);
    }

    public function estaAgotado(): bool
    {
        return $this->montoDisponible() <= 0;
    }

    /** Estado mostrado en Saldo a Favor (ticket 903 §3.3) — no confundir con `estado` (por_confirmar/confirmado). */
    public function estadoSaldo(): string
    {
        if ($this->montoDisponible() <= 0) {
            return 'agotado';
        }

        return $this->monto_usado > 0 ? 'usado_parcialmente' : 'disponible';
    }

    /** @return BelongsTo<AdminContInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AdminContInvoice::class, 'invoice_id');
    }

    /** @return BelongsTo<AdminContBankAccount, $this> */
    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(AdminContBankAccount::class, 'cuenta_bancaria_id');
    }

    /** @return BelongsTo<AdminContAbono, $this> */
    public function abonoOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'abono_origen_id');
    }
}
