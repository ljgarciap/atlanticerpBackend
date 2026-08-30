<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-241 RN1) — foto "antes"/"después" de la inspección. Siempre
 * privada (disco `s3-private`), sin versionado — mismo criterio que `TicketAttachment`.
 *
 * @property int         $id
 * @property int         $inspection_report_id
 * @property string      $categoria
 * @property string      $storage_key
 * @property int|null    $uploaded_by
 * @property \Carbon\Carbon $created_at
 * @property-read InspectionReport $inspectionReport
 * @property-read User|null $uploader
 */
class InspectionReportPhoto extends Model
{
    use LogsAuditTrail;

    public const CATEGORIA_ANTES = 'antes';

    public const CATEGORIA_DESPUES = 'despues';

    /** @var list<string> */
    public const CATEGORIAS = [self::CATEGORIA_ANTES, self::CATEGORIA_DESPUES];

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['inspection_report_id', 'categoria', 'storage_key', 'uploaded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'inspection_report_photos';
        }

        return "{$tenant->slug}_servicios.inspection_report_photos";
    }

    /** @return BelongsTo<InspectionReport, $this> */
    public function inspectionReport(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
