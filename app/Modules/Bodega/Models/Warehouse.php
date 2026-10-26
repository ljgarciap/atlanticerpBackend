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
 * @property bool         $excluye_disponible SCRUM-851 — cuenta en Total, se excluye de
 *   Disponible (Reserva Servicios, Merma, Reclamos y Devoluciones).
 * @property bool         $permite_venta SCRUM-1196 — puede surtir pedidos de venta (Central y Showroom Obarrio). Editable por superadmin.
 * @property bool         $despacho_por_defecto SCRUM-1196 — la bodega que el picking propone (exactamente una, de venta). Editable por superadmin.
 */
class Warehouse extends Model
{
    use LogsAuditTrail;

    public const MODO_UBICACION_EXACTA = 'ubicacion_exacta';
    public const MODO_PENDIENTE = 'pendiente';
    public const MODO_CLIENTE = 'cliente';

    /** @var list<string> */
    protected $fillable = [
        'name', 'responsable', 'capacidad_pct', 'modo_detalle', 'excluye_disponible',
        'permite_venta', 'despacho_por_defecto',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'capacidad_pct' => 'float', 'excluye_disponible' => 'boolean',
            'permite_venta' => 'boolean', 'despacho_por_defecto' => 'boolean',
        ];
    }

    /**
     * SCRUM-1196 — bodegas que pueden surtir pedidos de venta (`permite_venta`). La reserva, la hoja de picking y su validación
     * usan SOLO este scope: Reclamos, Merma, Reserva Servicios y los demás showrooms nunca se ofrecen ni se descuentan por una venta.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Warehouse> $query
     * @return \Illuminate\Database\Eloquent\Builder<Warehouse>
     */
    public function scopeParaVenta($query)
    {
        return $query->where('permite_venta', true);
    }

    /**
     * SCRUM-1196 — la bodega de despacho por defecto (Bodega Central). Null solo si un superadmin la dejó sin definir, caso que el panel impide.
     */
    public static function despachoPorDefecto(): ?self
    {
        return self::query()->where('despacho_por_defecto', true)->first();
    }

    /** Regla de validación: el `warehouse_id` debe existir. */
    public static function existsRule(): \Closure
    {
        // Closure y no `Rule::exists()`: con una tabla calificada por schema ("{tenant}_bodega.warehouses") Laravel la
        // interpreta como "conexión.tabla" (ver `StoreInventoryProductRequest::existsRule()`).
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_numeric($value)) {
                return;
            }
            if (! self::query()->whereKey((int) $value)->exists()) {
                $fail(trans('validation.exists', ['attribute' => $attribute]));
            }
        };
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
