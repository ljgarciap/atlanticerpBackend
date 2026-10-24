<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Lafayette\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-970/971 (épica SCRUM-969) — una fila por corrida REAL (no `--dry-run`) de
 * `lafayette:sync-productos`. Ver docblock de la migración `lafayette_sync_runs` para el porqué
 * (mismo criterio que `App\Modules\Legacy\Models\SyncRun`, sin capa de staging).
 *
 * Timestamps manuales (`started_at`/`finished_at`/`created_at`) — no usa el
 * `created_at`/`updated_at` automático de Eloquent, la tabla no tiene `updated_at`.
 *
 * @property int    $id
 * @property int    $extraidos
 * @property int    $creados
 * @property int    $vinculados       OBSOLETO desde SCRUM-990 (siempre 0; se retira en el Release E)
 * @property int    $coexistentes     OBSOLETO desde SCRUM-990 (siempre 0)
 * @property int    $actualizados
 * @property int    $sin_cambios      SCRUM-990 — filas idénticas a la fuente (no se escriben)
 * @property int    $fuera_de_fuente  SCRUM-990 — productos marcados `fuera_de_fuente_at` en la corrida
 * @property int    $excluidos        OBSOLETO desde SCRUM-990 (el espejo ya no excluye filas)
 * @property int    $errores
 * @property int    $duracion_ms
 * @property string $status
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon $finished_at
 * @property \Carbon\Carbon|null $created_at
 */
class LafayetteSyncRun extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED  = 'failed';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'extraidos', 'creados', 'vinculados', 'coexistentes', 'actualizados', 'sin_cambios', 'fuera_de_fuente', 'excluidos', 'errores',
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
            return 'lafayette_sync_runs';
        }

        return "{$tenant->slug}_ventas_diseno.lafayette_sync_runs";
    }
}
