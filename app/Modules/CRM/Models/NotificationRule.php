<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                  $id
 * @property string                $name
 * @property string                 $trigger_type
 * @property string|null              $trigger_model
 * @property string|null               $trigger_event
 * @property string|null                $field
 * @property string|null                 $operator
 * @property mixed                        $value
 * @property string|null                   $date_field
 * @property int|null                       $days_before
 * @property list<string>                    $channels
 * @property string                           $recipient_type
 * @property mixed                             $recipient_value
 * @property bool                               $is_active
 * @property int|null                            $created_by
 * @property \Carbon\Carbon                       $created_at
 * @property \Carbon\Carbon                        $updated_at
 */
class NotificationRule extends Model
{
    public const TRIGGER_MODEL_EVENT    = 'model_event';
    public const TRIGGER_DATE_PROXIMITY = 'date_proximity';

    public const RECIPIENT_USER              = 'user';
    public const RECIPIENT_ROLE              = 'role';
    public const RECIPIENT_PROJECT_ASSIGNEES = 'project_assignees';

    /** @var list<string> */
    public const OPERATORS = ['changed', 'changed_to', 'equals', 'gt', 'lt', 'gte', 'lte'];

    /** @var list<string> */
    protected $fillable = [
        'name',
        'trigger_type',
        'trigger_model',
        'trigger_event',
        'field',
        'operator',
        'value',
        'date_field',
        'days_before',
        'channels',
        'recipient_type',
        'recipient_value',
        'is_active',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value'           => 'json', // escalar (string/número/bool) o array — no siempre lista
            'channels'        => 'array',
            'recipient_value' => 'array',
            'is_active'       => 'boolean',
            'days_before'     => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
