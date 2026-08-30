<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229 RN — folio COT-SERV-AÑO-NNNN). Mismo patrón que
 * `InspectionReportFolioCounter` — `year` es la PK, ver `ServiceQuoteFolioService::next()`.
 *
 * @property int $year
 * @property int $last_number
 */
class ServiceQuoteFolioCounter extends Model
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
            return 'service_quote_folio_counters';
        }

        return "{$tenant->slug}_servicios.service_quote_folio_counters";
    }
}
