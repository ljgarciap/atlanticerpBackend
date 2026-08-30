<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Contador global (fila única, `id=1`) del folio F-XXXX — ver docblock de la migración
 * `create_admin_contab_invoice_folio_counters_table` para el porqué de no partir por año.
 *
 * @property int $id
 * @property int $last_number
 */
class AdminContInvoiceFolioCounter extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['id', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'invoice_folio_counters';
        }

        return "{$tenant->slug}_admin_contab.invoice_folio_counters";
    }
}
