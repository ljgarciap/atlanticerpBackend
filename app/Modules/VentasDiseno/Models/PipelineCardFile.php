<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $pipeline_card_id
 * @property string $type
 * @property string $file_name
 * @property string $storage_key
 * @property int    $size_bytes
 * @property string $mime_type
 * @property int    $uploaded_by
 * @property \Carbon\Carbon $created_at
 */
class PipelineCardFile extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'pipeline_card_id', 'type', 'file_name', 'storage_key', 'size_bytes', 'mime_type', 'uploaded_by',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pipeline_card_files';
        }

        return "{$tenant->slug}_ventas_diseno.pipeline_card_files";
    }

    /** @return BelongsTo<PipelineCard, $this> */
    public function pipelineCard(): BelongsTo
    {
        return $this->belongsTo(PipelineCard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
