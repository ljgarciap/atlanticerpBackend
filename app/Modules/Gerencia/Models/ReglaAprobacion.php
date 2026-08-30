<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * SCRUM-163 (REQ-101) — REGLA_APROBACION: tipo de solicitud → aprobador(es) autorizado(s).
 * Infraestructura configurable; el catálogo real de `tipo` y quién aprueba cada uno quedan
 * PENDIENTES DE DEFINIR CON EL CLIENTE (ver 6__Requerimientos_Gerencia.xlsx) — acá solo se
 * modela la estructura, sin datos reales sembrados.
 *
 * @property int         $id
 * @property string      $tipo
 * @property bool         $activo
 * @property string|null $observaciones
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReglaAprobacion extends Model
{
    /** @var list<string> */
    protected $fillable = ['tipo', 'activo', 'observaciones'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'reglas_aprobacion';
        }

        return "{$tenant->slug}_gerencia.reglas_aprobacion";
    }

    /** @return BelongsToMany<User, $this> */
    public function aprobadores(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot  = $tenant !== null
            ? "{$tenant->slug}_gerencia.regla_aprobacion_aprobadores"
            : 'regla_aprobacion_aprobadores';

        return $this->belongsToMany(User::class, $pivot, 'regla_aprobacion_id', 'user_id');
    }
}
