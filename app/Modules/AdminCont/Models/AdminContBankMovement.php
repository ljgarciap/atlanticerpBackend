<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
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
    public const TIPO_COBRO      = 'cobro';
    public const TIPO_DEVOLUCION = 'devolucion';
    public const TIPO_COMISION   = 'comision';

    /** Batch 18 (SCRUM-597→601) — trazabilidad hacia el registro de origen, ver ADR-SCRUM597-601. */
    public const SOURCE_PAYMENT              = 'payment';
    public const SOURCE_CREDIT_NOTE          = 'credit_note';
    public const SOURCE_ARCHITECT_COMMISSION = 'architect_commission';

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
}
