<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-329 Oleada A / Batch A1 — auditoría append-only de transiciones de etapa de un pedido.
 * Nunca se edita ni se borra, mismo criterio que `InventoryMovement` (Kardex) — por eso
 * `$timestamps = false` y no tiene `updated_at`. `changed_by` es nullable: la primera fila
 * (`from_stage = null`, `to_stage = 'asignado'`) la escribe el sistema al crear el pedido
 * automáticamente (REQ-308), sin un usuario humano detrás del clic.
 *
 * @property int             $id
 * @property int             $order_id
 * @property string|null     $from_stage
 * @property string          $to_stage
 * @property int|null        $changed_by
 * @property \Carbon\Carbon  $created_at
 * @property-read Order      $order
 * @property-read User|null  $changedByUser
 */
class OrderStageHistory extends Model
{
    use LogsAuditTrail;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['order_id', 'from_stage', 'to_stage', 'changed_by', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'order_stage_history';
        }

        return "{$tenant->slug}_bodega.order_stage_history";
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
