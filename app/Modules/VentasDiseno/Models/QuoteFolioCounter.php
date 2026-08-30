<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-051: contador atómico del folio COT-AAAA-NNNN, una fila por año calendario.
 * Ver QuoteFolioService::next() para el mecanismo de asignación sin colisiones.
 *
 * @property int $year
 * @property int $last_number
 */
class QuoteFolioCounter extends Model
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
            return 'quote_folio_counters';
        }

        return "{$tenant->slug}_ventas_diseno.quote_folio_counters";
    }
}
