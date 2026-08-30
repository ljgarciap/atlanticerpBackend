<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524 RN2) — observación editable de una fila del
 * Arqueo del día. `movement_type`/`movement_id` referencian polimórficamente un cobro
 * (`AdminContPayment`) o una nota de crédito (`AdminContCreditNote`) — sin FK real, la integridad
 * la garantiza `DailyCashCountService`.
 *
 * @property int         $id
 * @property int         $daily_cash_count_id
 * @property string      $movement_type
 * @property int         $movement_id
 * @property string|null $observacion
 */
class AdminContDailyCashCountEntry extends Model
{
    public const TYPE_PAYMENT     = 'payment';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    /** @var list<string> */
    protected $fillable = ['daily_cash_count_id', 'movement_type', 'movement_id', 'observacion'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'daily_cash_count_entries';
        }

        return "{$tenant->slug}_admin_contab.daily_cash_count_entries";
    }

    /** @return BelongsTo<AdminContDailyCashCount, $this> */
    public function dailyCashCount(): BelongsTo
    {
        return $this->belongsTo(AdminContDailyCashCount::class, 'daily_cash_count_id');
    }
}
