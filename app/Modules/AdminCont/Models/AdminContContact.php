<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-641 (REQ-564) — contactos generales por área.
 *
 * @property int    $id
 * @property string $area
 * @property string $email
 * @property string $telefono
 * @property bool   $activo
 */
class AdminContContact extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['area', 'email', 'telefono', 'activo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'contacts';
        }

        return "{$tenant->slug}_admin_contab.contacts";
    }
}
