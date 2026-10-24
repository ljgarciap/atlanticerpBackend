<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch 20 (SCRUM-612→617, REQ-538→540) — ver docblock de la migración de creación.
 *
 * @property int $id
 * @property string $numero
 * @property string $forma_pago
 * @property string $estado
 * @property int|null $realizado_por
 * @property int|null $aprobado_por
 * @property Carbon|null $fecha_aprobacion
 * @property Carbon $created_at
 * @property-read Collection<int, AdminContPettyCashExpense> $expenses
 * @property-read User|null $realizadoPor
 * @property-read User|null $aprobadoPor
 */
class AdminContPettyCashReport extends Model
{
    use LogsAuditTrail;

    public const FORMA_PAGO_TRANSFERENCIA = 'transferencia';

    public const FORMA_PAGO_EFECTIVO = 'efectivo';

    public const FORMA_PAGO_CHEQUE = 'cheque';

    public const FORMA_PAGO_YAPPY = 'yappy';

    /** @var list<string> */
    public const FORMAS_PAGO = [
        self::FORMA_PAGO_TRANSFERENCIA, self::FORMA_PAGO_EFECTIVO, self::FORMA_PAGO_CHEQUE, self::FORMA_PAGO_YAPPY,
    ];

    public const ESTADO_PENDIENTE_APROBACION = 'pendiente_aprobacion';

    public const ESTADO_FINALIZADO = 'finalizado';

    /**
     * Batch 21 (REQ-541 RN3/RN4) — el reporte se "disuelve": todas sus líneas se liberan hacia
     * Pendientes/Rechazados (`report_id` vuelve a `null` en cada una). La fila del reporte NUNCA
     * se borra — solo cambia a este estado terminal, para no reusar el folio y para que siga
     * apareciendo en la lista de Reportes con su pill correspondiente.
     *
     * Lo que se preserva es folio + estado + fecha, NO el contenido de sus líneas: `expenses()`
     * de un reporte `rechazado` devuelve una colección vacía (cada línea ya apunta a otro lado).
     * El historial de motivo/quién/cuándo de cada línea individual sigue completo en
     * `petty_cash_expense_history`, pero se consulta por el id de la línea, no por el reporte —
     * `GET reports/{numero}/pdf` devuelve 409 para este estado (ver
     * `AdminContPettyCashController::reportPdf()`), no hay "contenido del reporte" que descargar.
     */
    public const ESTADO_RECHAZADO = 'rechazado';

    /** @var list<string> */
    protected $fillable = [
        'numero', 'forma_pago', 'estado', 'realizado_por', 'aprobado_por', 'fecha_aprobacion',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_aprobacion' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'petty_cash_reports';
        }

        return "{$tenant->slug}_admin_contab.petty_cash_reports";
    }

    /** @return HasMany<AdminContPettyCashExpense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(AdminContPettyCashExpense::class, 'report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }

    /** @return BelongsTo<User, $this> */
    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
