<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 20 (REQ-535 RN2) — ver docblock de la migración de creación. Mismo patrón que
 * `Servicios\Models\TicketAttachment`/`AdminContPaymentAttachment`.
 *
 * @property int             $id
 * @property int             $expense_id
 * @property string          $nombre_archivo
 * @property string          $storage_key
 * @property int             $size_bytes
 * @property string          $mime_type
 * @property int|null        $uploaded_by
 * @property \Carbon\Carbon  $created_at
 * @property-read AdminContPettyCashExpense $expense
 * @property-read User|null $uploader
 */
class AdminContPettyCashExpenseAttachment extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'expense_id', 'nombre_archivo', 'storage_key', 'size_bytes', 'mime_type', 'uploaded_by',
    ];

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
            return 'petty_cash_expense_attachments';
        }

        return "{$tenant->slug}_admin_contab.petty_cash_expense_attachments";
    }

    /** @return BelongsTo<AdminContPettyCashExpense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(AdminContPettyCashExpense::class, 'expense_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
