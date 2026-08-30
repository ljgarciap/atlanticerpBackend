<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Contador global (fila única, `id=1`) del folio CONF-XXX — ver docblock de la migración
 * `create_admin_contab_payment_confirmation_folio_counters_table`.
 *
 * @property int $id
 * @property int $last_number
 */
class AdminContPaymentConfirmationFolioCounter extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['id', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payment_confirmation_folio_counters';
        }

        return "{$tenant->slug}_admin_contab.payment_confirmation_folio_counters";
    }
}
