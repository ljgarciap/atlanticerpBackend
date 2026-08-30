<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-271 RN3 — contador atómico del código COD-HER-NNN, fila única (`id` fijo en 1). Eloquent no
 * necesita nada especial más allá de desactivar el auto-incremento — ver `ToolCodeService::next()`
 * para el `INSERT ... ON CONFLICT` + `lockForUpdate()` que hace la asignación atómica real.
 *
 * @property int $id
 * @property int $last_number
 */
class ToolCodeCounter extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['id', 'last_number'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'tool_code_counters';
        }

        return "{$tenant->slug}_servicios.tool_code_counters";
    }
}
