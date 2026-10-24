<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 7 de Cobros (SCRUM-552, REQ-475) — registro de confirmación manual bancaria de un cobro
 * en "Esperando confirmación". Folio CONF-XXX propio (RN2), a lo sumo 1 por `payment_id` (RN4 —
 * no deshacible desde la pantalla). Mismo patrón general que `AdminContPaymentAttachment`.
 *
 * @property int             $id
 * @property int             $payment_id
 * @property string          $numero_confirmacion
 * @property int|null        $confirmed_by
 * @property \Carbon\Carbon  $confirmed_at
 * @property-read AdminContPayment $payment
 * @property-read User|null $confirmedBy
 */
class AdminContPaymentConfirmation extends Model
{
    use LogsAuditTrail;

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['payment_id', 'numero_confirmacion', 'confirmed_by', 'confirmed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payment_confirmations';
        }

        return "{$tenant->slug}_admin_contab.payment_confirmations";
    }

    /** @return BelongsTo<AdminContPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(AdminContPayment::class, 'payment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
