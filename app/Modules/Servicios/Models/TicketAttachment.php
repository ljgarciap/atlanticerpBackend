<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 4 (REQ-248) — foto/video/archivo de referencia adjuntado a un ticket.
 * Siempre privado (disco `s3-private`), sin versionado — a diferencia de `crm.ProjectDocument`.
 *
 * @property int         $id
 * @property int         $ticket_id
 * @property string      $nombre_archivo
 * @property string      $storage_key
 * @property int         $size_bytes
 * @property string      $mime_type
 * @property int|null    $uploaded_by
 * @property \Carbon\Carbon $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $uploader
 */
class TicketAttachment extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id', 'nombre_archivo', 'storage_key', 'size_bytes', 'mime_type', 'uploaded_by',
    ];

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
            return 'ticket_attachments';
        }

        return "{$tenant->slug}_servicios.ticket_attachments";
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
