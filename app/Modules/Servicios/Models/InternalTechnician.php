<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fase 4 — Servicios, Batch 6 (REQ-255→260) — perfil de técnico interno. Ver docblock de la
 * migración `2026_08_09_100002_create_servicios_internal_technicians_table.php` sobre por qué
 * `user_id` es nullable.
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $nombre
 * @property string|null $telefono
 * @property string|null $email
 * @property string      $especialidad
 * @property string      $color
 * @property bool         $has_bonus_plan
 * @property array{start: string, end: string} $working_hours
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 * @property-read User|null $user
 */
class InternalTechnician extends Model
{
    use LogsAuditTrail;
    // SCRUM-1015 — "Eliminar" es soft delete: el historial que referencia al técnico no se rompe.
    use SoftDeletes;

    public const ESPECIALIDAD_GENERAL = 'general';

    public const ESPECIALIDAD_WARRANTY = 'warranty';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'nombre', 'telefono', 'email', 'especialidad', 'color', 'working_hours',
        'has_bonus_plan',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'working_hours'  => 'array',
            'has_bonus_plan' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'internal_technicians';
        }

        return "{$tenant->slug}_servicios.internal_technicians";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
