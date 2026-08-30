<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audiencia con nombre, reutilizable — usada por circulares manuales (Fase 3).
 * Genérico: solo conoce user_id, nunca un concepto de CRM.
 *
 * @property int                  $id
 * @property string                $name
 * @property string|null            $description
 * @property list<int>               $members
 * @property int|null                 $created_by
 * @property \Carbon\Carbon           $created_at
 * @property \Carbon\Carbon           $updated_at
 */
class NotificationList extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'members',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'members' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
