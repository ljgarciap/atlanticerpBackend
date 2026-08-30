<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Contador por año (PK `anio`) del folio `NNNN-AAAA` — ver docblock de la migración
 * `create_admin_contab_petty_cash_report_folio_counters_table`. Mismo patrón de
 * `getTable()`/`$incrementing = false` que `AdminContCreditNoteFolioCounter`.
 *
 * @property int $anio
 * @property int $last_number
 */
class AdminContPettyCashReportFolioCounter extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'anio';

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['anio', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'petty_cash_report_folio_counters';
        }

        return "{$tenant->slug}_admin_contab.petty_cash_report_folio_counters";
    }
}
