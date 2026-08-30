<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feed in-app de un usuario (consumido por la campana del TopBar). `content` es una copia
 * independiente del content del NotificationSend — sobrevive aunque el send se borre.
 *
 * @property int                    $id
 * @property int                     $user_id
 * @property int|null                  $notification_send_id
 * @property array<string, mixed>       $content
 * @property string|null                 $link_url
 * @property \Carbon\Carbon|null           $read_at
 * @property \Carbon\Carbon|null            $archived_at
 * @property \Carbon\Carbon                  $created_at
 */
class Notification extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'notification_send_id',
        'content',
        'link_url',
        'read_at',
        'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'content'     => 'array',
            'read_at'     => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<NotificationSend, $this> */
    public function send(): BelongsTo
    {
        return $this->belongsTo(NotificationSend::class, 'notification_send_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
