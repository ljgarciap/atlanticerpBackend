<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-750 — una fila por corrida REAL (no `--dry-run`) de un comando `legacy:sync-*`. Ver
 * docblock de la migración para el porqué (vacío de monitoreo #5 del diseño).
 *
 * Timestamps manuales (`started_at`/`finished_at`/`created_at`) — no usa el
 * `created_at`/`updated_at` automático de Eloquent, la tabla no tiene `updated_at`.
 *
 * @property int    $id
 * @property string $entidad
 * @property string $origen
 * @property int    $extraidos
 * @property int    $staged
 * @property int    $mapeados
 * @property int    $cargados
 * @property int    $excluidos
 * @property int    $errores
 * @property int    $duracion_ms
 * @property string $status
 * @property array<string, mixed>|null $resumen SCRUM-1114 — informe de divergencia (solo la entidad `stock`)
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon $finished_at
 * @property \Carbon\Carbon|null $created_at
 */
class SyncRun extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED  = 'failed';

    /**
     * SCRUM-838 — circuit breaker de cambio grande: una corrida incremental cuyo volumen de
     * cambio superó el umbral definido no aplica sus cambios (Matcher/Loader-B nunca corren) y
     * queda en este status en vez de success/partial — requiere revisión manual
     * (infra/legacy-sync.sh <entorno> --<entidad> --force) antes de aplicarse.
     */
    public const STATUS_HELD_FOR_REVIEW = 'held_for_review';

    public const ORIGEN_MANUAL    = 'manual';
    public const ORIGEN_SCHEDULER = 'scheduler';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'entidad', 'origen', 'extraidos', 'staged', 'mapeados', 'cargados', 'excluidos', 'errores',
        'duracion_ms', 'status', 'resumen', 'started_at', 'finished_at', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resumen'     => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sync_runs';
        }

        return "{$tenant->slug}_legacy.sync_runs";
    }
}
