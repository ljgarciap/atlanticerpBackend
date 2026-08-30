<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-473→489 (Bloque B6 "Devoluciones") — contador atómico del folio DEV-AAAA-NNNN de
 * Devoluciones, una fila por año calendario. Ver
 * `App\Modules\Bodega\Services\ReturnFolioService::next()` para el mecanismo de asignación sin
 * colisiones — mismo patrón que `App\Modules\Bodega\Models\OrderFolioCounter`.
 *
 * @property int $year
 * @property int $last_number
 */
class ReturnFolioCounter extends Model
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
            return 'return_folio_counters';
        }

        return "{$tenant->slug}_bodega.return_folio_counters";
    }
}
