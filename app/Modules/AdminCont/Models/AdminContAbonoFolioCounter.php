<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Contador global (fila única, `id=1`) del folio ABN-XXX — ver docblock de la migración
 * `create_admin_contab_abono_folio_counters_table`. Secuencia propia, independiente del REC-XXX de
 * `AdminContPaymentFolioCounter`.
 *
 * @property int $id
 * @property int $last_number
 */
class AdminContAbonoFolioCounter extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['id', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'abono_folio_counters';
        }

        return "{$tenant->slug}_admin_contab.abono_folio_counters";
    }
}
