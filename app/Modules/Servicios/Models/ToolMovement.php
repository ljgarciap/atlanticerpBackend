<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-269 RN3, REQ-271 RN4, REQ-272 RN4) — entrada
 * inmutable del kardex de herramientas. Solo consulta, nunca se edita ni se borra — sin
 * `UPDATED_AT`, mismo patrón que `ExternalTechnicianRateHistory`.
 *
 * @property int         $id
 * @property int         $tool_id
 * @property string      $tipo
 * @property string|null $detalle
 * @property int|null    $user_id
 * @property \Carbon\Carbon $created_at
 * @property-read Tool $tool
 * @property-read User|null $user
 */
class ToolMovement extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    public const TIPO_INGRESO = 'ingreso';

    public const TIPO_DAMAGED = 'damaged';

    public const TIPO_LOST = 'lost';

    public const TIPO_WORN = 'worn';

    /** @var list<string> */
    protected $fillable = ['tool_id', 'tipo', 'detalle', 'user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'tool_movements';
        }

        return "{$tenant->slug}_servicios.tool_movements";
    }

    /** @return BelongsTo<Tool, $this> */
    public function tool(): BelongsTo
    {
        return $this->belongsTo(Tool::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
