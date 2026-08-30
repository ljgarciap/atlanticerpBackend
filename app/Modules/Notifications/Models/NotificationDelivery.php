<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log de entrega por canal externo (hoy solo email) — éxito/fallo por destinatario.
 * No existe fila de delivery para el canal in_app (ese estado vive directo en Notification).
 *
 * @property int                $id
 * @property int                 $notification_send_id
 * @property int                  $user_id
 * @property string                $channel
 * @property string                 $status
 * @property string|null              $error
 * @property \Carbon\Carbon           $created_at
 */
class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'notification_send_id',
        'user_id',
        'channel',
        'status',
        'error',
    ];

    /** @return BelongsTo<NotificationSend, $this> */
    public function send(): BelongsTo
    {
        return $this->belongsTo(NotificationSend::class, 'notification_send_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
