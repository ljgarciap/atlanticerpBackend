<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de tasas de ITBMS (SCRUM-635/636). `es_base` queda fuera de `$fillable` a propósito —
 * solo las 3 filas sembradas por la migración lo tienen en true, nunca se crea/edita vía request
 * (RN1 de REQ-558: las tasas base nunca se eliminan ni se crean por API).
 *
 * @property int         $id
 * @property string      $nombre
 * @property string      $descripcion
 * @property float        $porcentaje
 * @property bool        $es_base
 * @property bool        $activa
 * @property int|null    $created_by
 */
class AdminContItbmsRate extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['nombre', 'descripcion', 'porcentaje', 'activa', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'porcentaje' => 'float',
            'es_base'    => 'boolean',
            'activa'     => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'itbms_rates';
        }

        return "{$tenant->slug}_admin_contab.itbms_rates";
    }
}
