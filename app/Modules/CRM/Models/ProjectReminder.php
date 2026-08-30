<?php

namespace App\Modules\CRM\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int            $id
 * @property int            $project_id
 * @property string         $tipo
 * @property \Carbon\Carbon $fecha
 * @property string|null    $nota
 * @property bool           $completado
 * @property \Carbon\Carbon $created_at
 * @property Project        $project
 */
class ProjectReminder extends Model
{
    // Only created_at exists in the schema — no updated_at column
    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'tipo',
        'fecha',
        'nota',
        'completado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha'      => 'date',
            'completado' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'project_reminders';
        }

        return "{$tenant->slug}_crm.project_reminders";
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
