<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-238 RN1 — contador atómico del folio INF-AÑO-NNNN, una fila por año calendario. `year` es
 * la PK — mismo patrón que `VentasDiseno\QuoteFolioCounter`, ver `InspectionReportFolioService::next()`.
 *
 * @property int $year
 * @property int $last_number
 */
class InspectionReportFolioCounter extends Model
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
            return 'inspection_report_folio_counters';
        }

        return "{$tenant->slug}_servicios.inspection_report_folio_counters";
    }
}
