<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-640 (REQ-563) — ubicaciones administrativas (tipo `oficina`/`otra`). Las 7 bodegas/showrooms
 * oficiales viven en `App\Modules\Bodega\Models\Warehouse`, nunca acá — ver docblock de la migración.
 *
 * @property int         $id
 * @property string      $nombre
 * @property string      $tipo
 * @property string|null $direccion
 * @property bool        $activa
 */
class AdminContLocation extends Model
{
    use LogsAuditTrail;

    public const TIPO_OFICINA = 'oficina';
    public const TIPO_OTRA    = 'otra';

    /** @var list<string> */
    protected $fillable = ['nombre', 'tipo', 'direccion', 'activa'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'locations';
        }

        return "{$tenant->slug}_admin_contab.locations";
    }
}
