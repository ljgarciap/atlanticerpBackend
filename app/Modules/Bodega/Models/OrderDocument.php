<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-341) — documento generado de un pedido (Guía de entrega,
 * Estatus de pedido). Append-only igual que `OrderStageHistory`: una regeneración crea una fila
 * nueva, nunca edita la existente — por eso `$timestamps = false` y no tiene `updated_at`.
 *
 * @property int             $id
 * @property int             $order_id
 * @property string          $tipo
 * @property string          $storage_key
 * @property string|null     $received_by_name
 * @property int|null        $uploaded_by
 * @property \Carbon\Carbon  $created_at
 * @property-read Order      $order
 * @property-read User|null  $uploadedBy
 */
class OrderDocument extends Model
{
    use LogsAuditTrail;

    public const TIPO_GUIA_ENTREGA = 'guia_entrega';

    public const TIPO_ESTATUS_PEDIDO = 'estatus_pedido';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['order_id', 'tipo', 'storage_key', 'received_by_name', 'uploaded_by', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'order_documents';
        }

        return "{$tenant->slug}_bodega.order_documents";
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
