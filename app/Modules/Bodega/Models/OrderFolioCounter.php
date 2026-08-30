<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * HU-335: contador atómico del folio PED-AAAA-NNNN de Pedidos, una fila por año calendario. Ver
 * `App\Modules\Bodega\Services\OrderFolioService::next()` para el mecanismo de asignación sin
 * colisiones — mismo patrón que `App\Modules\VentasDiseno\Models\QuoteFolioCounter`.
 *
 * @property int $year
 * @property int $last_number
 */
class OrderFolioCounter extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['year', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'order_folio_counters';
        }

        return "{$tenant->slug}_bodega.order_folio_counters";
    }
}
