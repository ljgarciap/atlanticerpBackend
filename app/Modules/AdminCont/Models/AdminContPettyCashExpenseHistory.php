<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 21 (REQ-542 RN4, REQ-544 RN3, REQ-545 RN3) — ver docblock de la migración de creación.
 *
 * @property int $id
 * @property int $expense_id
 * @property string $accion
 * @property string $motivo
 * @property int|null $actor_id
 * @property Carbon $created_at
 * @property-read AdminContPettyCashExpense $expense
 * @property-read User|null $actor
 */
class AdminContPettyCashExpenseHistory extends Model
{
    public const UPDATED_AT = null;

    public const ACCION_RECHAZO = 'rechazo';

    public const ACCION_REAPERTURA = 'reapertura';

    /** @var list<string> */
    protected $fillable = ['expense_id', 'accion', 'motivo', 'actor_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'petty_cash_expense_history';
        }

        return "{$tenant->slug}_admin_contab.petty_cash_expense_history";
    }

    /** @return BelongsTo<AdminContPettyCashExpense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(AdminContPettyCashExpense::class, 'expense_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
