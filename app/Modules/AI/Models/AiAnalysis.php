<?php

declare(strict_types=1);

namespace App\Modules\AI\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string      $id
 * @property string      $entity_type
 * @property int|null    $entity_id
 * @property string|null $document_hash
 * @property string      $analysis_type
 * @property string|null $model
 * @property int|null    $input_tokens
 * @property int|null    $output_tokens
 * @property float|null  $estimated_cost_usd
 * @property string      $status
 * @property string|null $result
 * @property string|null $error
 * @property int|null    $requested_by
 * @property array<string, mixed> $extra
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AiAnalysis extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public $incrementing = false;
    protected $keyType   = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'entity_type',
        'entity_id',
        'document_hash',
        'analysis_type',
        'model',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'status',
        'result',
        'error',
        'requested_by',
        'extra',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'extra'               => 'array',
            'entity_id'           => 'integer',
            'requested_by'        => 'integer',
            'input_tokens'        => 'integer',
            'output_tokens'       => 'integer',
            'estimated_cost_usd'  => 'float',
        ];
    }

    public static function boot(): void
    {
        parent::boot();
        static::creating(static function (self $model): void {
            if ($model->id === '' || $model->id === null) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('No active tenant when querying ai_analyses.');
        }

        return "{$tenant->slug}_crm.ai_analyses";
    }

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isRunning(): bool   { return $this->status === self::STATUS_RUNNING; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
    public function isFailed(): bool    { return $this->status === self::STATUS_FAILED; }
}
