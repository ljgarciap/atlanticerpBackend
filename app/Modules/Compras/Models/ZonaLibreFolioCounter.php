<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila única (id=1) — contador atómico de `bodega_zona_libre_requests.order_number`. Ver
 * `App\Modules\Compras\Services\ZonaLibreFolioService::next()` para el mecanismo de asignación
 * sin colisiones (mismo patrón que `App\Modules\Bodega\Models\OrderFolioCounter`/
 * `App\Modules\VentasDiseno\Models\QuoteFolioCounter`, sin la dimensión de año).
 *
 * @property int $id
 * @property int $last_number
 */
class ZonaLibreFolioCounter extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['id', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'bodega_zona_libre_folio_counters';
        }

        return "{$tenant->slug}_compras.bodega_zona_libre_folio_counters";
    }
}
