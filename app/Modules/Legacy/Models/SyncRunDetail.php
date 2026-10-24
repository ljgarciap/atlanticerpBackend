<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-816 — detalle fila-por-fila de una corrida de `SyncRun`, solo para filas excluded/orphan/
 * ambiguous/error. Ver docblock de la migración para el porqué.
 *
 * @property int    $id
 * @property int    $sync_run_id
 * @property string $referencia
 * @property string $status
 * @property string|null $motivo
 * @property \Carbon\Carbon|null $created_at
 */
class SyncRunDetail extends Model
{
    public const STATUS_EXCLUDED  = 'excluded';
    public const STATUS_ORPHAN    = 'orphan';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_ERROR     = 'error';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['sync_run_id', 'referencia', 'status', 'motivo', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<SyncRun, $this> */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sync_run_details';
        }

        return "{$tenant->slug}_legacy.sync_run_details";
    }
}
