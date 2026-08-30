<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch 20 (SCRUM-612→617, REQ-535→537) — ver docblock de la migración de creación.
 *
 * @property int $id
 * @property Carbon $fecha
 * @property int|null $solicitante_id
 * @property string $proveedor
 * @property string $descripcion
 * @property float $monto_bruto
 * @property float $itbms
 * @property int|null $report_id
 * @property int $intentos_rechazo
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property-read User|null $solicitante
 * @property-read AdminContPettyCashReport|null $report
 * @property-read User|null $creator
 * @property-read Collection<int, AdminContPettyCashExpenseAttachment> $attachments
 * @property-read Collection<int, AdminContPettyCashExpenseHistory> $history
 */
class AdminContPettyCashExpense extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    /** REQ-537 RN3 — "Rechazado (1/2)", primer rechazo, todavía puede corregirse. */
    public const ESTADO_RECHAZADO_TEMPORAL = 'rechazado_temporal';

    /** Batch 21 (REQ-544 RN2) — reabierta desde "Rechazados", contador en 0 de nuevo. */
    public const ESTADO_REABIERTO = 'reabierto';

    /** Batch 21 (REQ-542 RN3) — llegó al umbral de intentos (`petty_cash_max_intentos_rechazo`,
     *  ver `AdminContFiscalSettings`), pasó permanentemente a "Rechazados". */
    public const ESTADO_RECHAZADO_PERMANENTE = 'rechazado_permanente';

    /** @var list<string> */
    protected $fillable = [
        'fecha', 'solicitante_id', 'proveedor', 'descripcion', 'monto_bruto', 'itbms',
        'report_id', 'intentos_rechazo', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto_bruto' => 'float',
            'itbms' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'petty_cash_expenses';
        }

        return "{$tenant->slug}_admin_contab.petty_cash_expenses";
    }

    /** RN5 — Monto Bruto + ITBMS, nunca persistido. */
    public function getTotalAttribute(): float
    {
        return round($this->monto_bruto + $this->itbms, 2);
    }

    /**
     * REQ-537 RN3 / REQ-542 RN3 / REQ-544 RN2 — mismo criterio que `estadoLinea()` del mockup.
     * Requiere `history` cargada (`with('history')`) para poder distinguir "nunca rechazada" de
     * "reabierta" sin disparar una query por línea — `intentos_rechazo` solo no alcanza, ambos
     * casos lo tienen en 0.
     *
     * `$maxIntentos` es el umbral configurable (`AdminContFiscalSettings::petty_cash_max_intentos_rechazo`,
     * default 2) — quien mapea muchas líneas a la vez (`AdminContPettyCashService::pending()`/
     * `rejected()`/etc.) debe resolverlo UNA vez y pasarlo acá, para no disparar una query del
     * singleton de settings por fila. Si se omite, esta llamada lo resuelve sola (uso puntual,
     * fuera de un loop).
     */
    public function estado(?int $maxIntentos = null): string
    {
        $max = $maxIntentos ?? AdminContFiscalSettings::current()->petty_cash_max_intentos_rechazo;

        if ($this->intentos_rechazo >= $max) {
            return self::ESTADO_RECHAZADO_PERMANENTE;
        }

        if ($this->intentos_rechazo > 0) {
            return self::ESTADO_RECHAZADO_TEMPORAL;
        }

        if ($this->history->contains(
            fn (AdminContPettyCashExpenseHistory $h) => $h->accion === AdminContPettyCashExpenseHistory::ACCION_REAPERTURA,
        )) {
            return self::ESTADO_REABIERTO;
        }

        return self::ESTADO_PENDIENTE;
    }

    /** @return BelongsTo<User, $this> */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /** @return BelongsTo<AdminContPettyCashReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AdminContPettyCashReport::class, 'report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<AdminContPettyCashExpenseAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(AdminContPettyCashExpenseAttachment::class, 'expense_id');
    }

    /** @return HasMany<AdminContPettyCashExpenseHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(AdminContPettyCashExpenseHistory::class, 'expense_id')->orderBy('created_at');
    }
}
