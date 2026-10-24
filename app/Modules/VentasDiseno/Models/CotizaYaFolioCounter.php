<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1120 — contador atómico del folio COTY-AAAA-NNNN, una fila por año calendario. Serie propia, separada de
 * `QuoteFolioCounter` (COT-). Ver `CotizaYaFolioService::next()`.
 *
 * @property int $year
 * @property int $last_number
 */
class CotizaYaFolioCounter extends Model
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
            return 'cotizaya_folio_counters';
        }

        return "{$tenant->slug}_ventas_diseno.cotizaya_folio_counters";
    }
}
