<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-307) — mapeo configurable vendedor→Asistente de Bodega.
 * Fuente de verdad para la asignación automática de `Order.assistant_id` al aprobar una
 * cotización (REQ-308). "Configurable" (RN3) = tabla editable, nunca un `match()`/array
 * hardcodeado con nombres — ver `database/seeders/WarehouseAssistantMappingSeeder.php` para el
 * seed inicial real (Asistente Bodega Test 2 / Asistente Bodega Test, confirmado contra Jira SCRUM-377).
 *
 * `is_active` permite reasignar un vendedor a otro asistente sin perder el historial: la
 * migración garantiza (índice único parcial) una sola fila activa por `seller_user_id` a la vez.
 *
 * @property int $id
 * @property int $seller_user_id
 * @property int $assistant_user_id
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User        $seller
 * @property-read User        $assistant
 */
class WarehouseAssistantMapping extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['seller_user_id', 'assistant_user_id', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'warehouse_assistant_mapping';
        }

        return "{$tenant->slug}_bodega.warehouse_assistant_mapping";
    }

    /** @return BelongsTo<User, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_user_id');
    }
}
