<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-451 (REQ-381) — catálogo fijo de 7 bodegas físicas, sembrado por migración, sin CRUD
 * desde UI (ver docblock de la migración de creación). Reemplaza el catálogo de 5 de
 * SCRUM-244/REQ-181.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $responsable
 * @property float|null  $capacidad_pct
 * @property string      $modo_detalle ubicacion_exacta|pendiente|cliente — ver REQ-385
 */
class Warehouse extends Model
{
    use LogsAuditTrail;

    public const MODO_UBICACION_EXACTA = 'ubicacion_exacta';
    public const MODO_PENDIENTE = 'pendiente';
    public const MODO_CLIENTE = 'cliente';

    /** @var list<string> */
    protected $fillable = ['name', 'responsable', 'capacidad_pct', 'modo_detalle'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['capacidad_pct' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'warehouses';
        }

        return "{$tenant->slug}_bodega.warehouses";
    }

    /** @return HasMany<ProductWarehouseStock, $this> */
    public function stock(): HasMany
    {
        return $this->hasMany(ProductWarehouseStock::class);
    }
}
