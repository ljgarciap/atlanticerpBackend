<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-750 — staging de PRECIOSVENTA, solo las 4 tarifas vigentes mapeadas (ver
 * `App\Modules\Legacy\Support\PriceTypeMap`). UNIQUE compuesto `(icg_codarticulo, idtarifav)`.
 *
 * @property int      $id
 * @property int      $icg_codarticulo
 * @property int      $idtarifav
 * @property float    $precio_neto
 * @property float|null $precio_bruto
 * @property float|null $descuento
 * @property bool      $descatalogado_fila
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ProductoPrecio extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'icg_codarticulo', 'idtarifav', 'precio_neto', 'precio_bruto', 'descuento',
        'descatalogado_fila',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'precio_neto'        => 'float',
            'precio_bruto'       => 'float',
            'descuento'          => 'float',
            'descatalogado_fila' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'producto_precios';
        }

        return "{$tenant->slug}_legacy.producto_precios";
    }
}
