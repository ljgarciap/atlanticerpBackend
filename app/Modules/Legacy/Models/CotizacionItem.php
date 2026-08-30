<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-757 — staging de PRESUPUESTOSLIN. Sin llave natural propia (ver docblock de la migración)
 * — `CotizacionStagingLoader` idempotiza esta tabla con un patrón delete-then-insert scoped a las
 * cabeceras de la corrida actual, no con upsert por columna única.
 *
 * @property int         $id
 * @property int         $legacy_cotizacion_id
 * @property int|null    $icg_codarticulo
 * @property string|null $referencia
 * @property string|null $descripcion
 * @property string|null $cantidad
 * @property string|null $precio
 * @property string|null $descuento
 * @property string|null $total
 * @property string|null $costo
 * @property-read Cotizacion $cotizacion
 */
class CotizacionItem extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'legacy_cotizacion_id', 'icg_codarticulo', 'referencia', 'descripcion', 'cantidad',
        'precio', 'descuento', 'total', 'costo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad'  => 'decimal:2',
            'precio'    => 'decimal:2',
            'descuento' => 'decimal:2',
            'total'     => 'decimal:2',
            'costo'     => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Cotizacion, $this> */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'legacy_cotizacion_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'cotizacion_items';
        }

        return "{$tenant->slug}_legacy.cotizacion_items";
    }
}
