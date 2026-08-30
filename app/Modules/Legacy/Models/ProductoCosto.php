<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-750 — staging de PRECIOSCOMPRA, sin filtrar (1 fila por artículo+proveedor). UNIQUE
 * compuesto `(icg_codarticulo, codproveedor)`.
 *
 * @property int   $id
 * @property int   $icg_codarticulo
 * @property int   $codproveedor
 * @property float $costo_neto
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ProductoCosto extends Model
{
    /** @var list<string> */
    protected $fillable = ['icg_codarticulo', 'codproveedor', 'costo_neto'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'costo_neto' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'producto_costos';
        }

        return "{$tenant->slug}_legacy.producto_costos";
    }
}
