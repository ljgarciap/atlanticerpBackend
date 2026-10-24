<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-610→611 (REQ-533→534). Ledger fundacional — ver docblock de la migración de creación
 * para qué módulos futuros van a insertar acá.
 *
 * @property int         $id
 * @property int|null    $bank_account_id
 * @property \Carbon\Carbon $fecha
 * @property string      $tipo
 * @property string      $concepto
 * @property float       $monto
 * @property string|null $source_type
 * @property int|null    $source_id
 */
class AdminContBankMovement extends Model
{
    use LogsAuditTrail;

    public const TIPO_COBRO      = 'cobro';
    public const TIPO_DEVOLUCION = 'devolucion';
    public const TIPO_COMISION   = 'comision';

    /** Batch 18 (SCRUM-597→601) — trazabilidad hacia el registro de origen, ver ADR-SCRUM597-601. */
    public const SOURCE_PAYMENT              = 'payment';
    // SCRUM-1168 F4/F5 — salida de caja por anular un cobro, y por devolver dinero al cliente. Ambas se restan en posición de caja (tipo devolución) y
    // figuran en el arqueo del día en que ocurren.
    public const SOURCE_PAYMENT_REVERSAL     = 'payment_reversal';
    public const SOURCE_CLIENT_REFUND        = 'client_refund';
    public const SOURCE_CREDIT_NOTE          = 'credit_note';
    public const SOURCE_ARCHITECT_COMMISSION = 'architect_commission';

    /** SCRUM-879 — liquidación de comisión interna (por `CommissionMonthSnapshot`), generada por
     *  el job `commissions:settle-internal` al cierre de mes (source_id = snapshot.id). */
    public const SOURCE_INTERNAL_COMMISSION = 'internal_commission';

    /** @var list<string> */
    protected $fillable = ['bank_account_id', 'fecha', 'tipo', 'concepto', 'monto', 'source_type', 'source_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'bank_movements';
        }

        return "{$tenant->slug}_admin_contab.bank_movements";
    }

    /** @return BelongsTo<AdminContBankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(AdminContBankAccount::class, 'bank_account_id');
    }

    /** RN4 de REQ-533 — cobro = entrada; devolución/comisión = salida. */
    public function direccion(): string
    {
        return $this->tipo === self::TIPO_COBRO ? 'entrada' : 'salida';
    }

    /**
     * SCRUM-879 (decisión E-3) — movimientos que impactan el flujo de caja REAL: los de cuentas
     * activas, MÁS los de comisión sin cuenta asignada todavía (el dinero ya salió de la empresa;
     * asignar la cuenta es solo categorización). Mismo criterio en `bankBalance()`, `real()` y
     * `netFlowForWindow()`.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @param \Illuminate\Support\Collection<int, int>|list<int> $activeAccountIds
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeRealCashImpact($query, $activeAccountIds)
    {
        $ids = $activeAccountIds instanceof \Illuminate\Support\Collection
            ? $activeAccountIds->all()
            : $activeAccountIds;

        return $query->where(function ($q) use ($ids): void {
            $q->whereIn('bank_account_id', $ids)
                ->orWhere(function ($q2): void {
                    $q2->where('tipo', self::TIPO_COMISION)->whereNull('bank_account_id');
                });
        });
    }
}
