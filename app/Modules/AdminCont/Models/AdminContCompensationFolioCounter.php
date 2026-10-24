<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1168 F3 — contador del folio `CMP-nnn` de las compensaciones (una sola fila, id=1).
 *
 * @property int $last_number
 */
class AdminContCompensationFolioCounter extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['id', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'compensation_folio_counters' : "{$tenant->slug}_admin_contab.compensation_folio_counters";
    }
}
