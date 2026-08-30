<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-454 (REQ-384) — catálogo editable de ubicaciones físicas de una bodega (para el chip
 * "Espacio libre"). `is_active=false` en vez de hard-delete, ver docblock de la migración.
 *
 * @property int         $id
 * @property int         $warehouse_id
 * @property string      $codigo
 * @property bool         $is_active
 * @property-read Warehouse|null $warehouse
 */
class WarehouseLocation extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['warehouse_id', 'codigo', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'warehouse_locations';
        }

        return "{$tenant->slug}_bodega.warehouse_locations";
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
