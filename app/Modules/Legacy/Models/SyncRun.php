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
 * @property int    $extraidos
 * @property int    $staged
 * @property int    $mapeados
 * @property int    $cargados
 * @property int    $excluidos
 * @property int    $errores
 * @property int    $duracion_ms
 * @property string $status
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon $finished_at
 * @property \Carbon\Carbon|null $created_at
 */
class SyncRun extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED  = 'failed';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'entidad', 'extraidos', 'staged', 'mapeados', 'cargados', 'excluidos', 'errores',
        'duracion_ms', 'status', 'started_at', 'finished_at', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
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
