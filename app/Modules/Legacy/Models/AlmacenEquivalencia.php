<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1136 F1 — equivalencia MANUAL almacén ICG → bodega de AtlanticERP (`legacy.almacen_equivalencias`).
 * `warehouse_id` es nullable y sin FK (cero acoplamiento con `bodega.*`); `estado` propuesto|confirmado.
 * No se usa para mover stock hasta confirmar; ningún comando la escribe.
 *
 * @property int         $id
 * @property string      $codalmacen_icg
 * @property int|null    $warehouse_id
 * @property string      $estado
 * @property string|null $nota
 */
class AlmacenEquivalencia extends Model
{
    public const ESTADO_PROPUESTO  = 'propuesto';
    public const ESTADO_CONFIRMADO = 'confirmado';

    /** @var list<string> */
    protected $fillable = ['codalmacen_icg', 'warehouse_id', 'estado', 'nota'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'almacen_equivalencias';
        }

        return "{$tenant->slug}_legacy.almacen_equivalencias";
    }
}
