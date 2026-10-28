<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int                          $id
 * @property string                       $nombre
 * @property string|null                  $contacto
 * @property string|null                  $email
 * @property string|null                  $celular
 * @property string|null                  $encargado
 * @property string|null                  $developer
 * @property string|null                  $arquitecto
 * @property string                       $tipo
 * @property bool                         $requires_technical_study
 * @property string|null                  $area
 * @property string|null                  $despacho
 * @property string|null                  $ubicacion
 * @property string                       $etapa
 * @property \Carbon\Carbon|null          $fecha_entrega
 * @property float|null                   $valor
 * @property string|null                  $notas
 * @property int|null                     $created_by
 * @property \Carbon\Carbon               $created_at
 * @property \Carbon\Carbon               $updated_at
 * @property Collection<int, User>            $assignees
 * @property Collection<int, ProjectActivity> $activities
 * @property ProjectActivity|null             $latestActivity
 * @property Collection<int, ProjectReminder> $reminders
 * @property Collection<int, ProjectDocument> $documents
 * @property int                          $documents_count
 * @property User|null                    $creator
 */
class Project extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'nombre',
        'contacto',
        'email',
        'celular',
        'encargado',
        'developer',
        'arquitecto',
        'tipo',
        'requires_technical_study',
        'area',
        'despacho',
        'ubicacion',
        'etapa',
        'fecha_entrega',
        'valor',
        'notas',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requires_technical_study'        => 'boolean',
            'valor'         => 'float',
            'fecha_entrega' => 'date',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'projects';
        }

        return "{$tenant->slug}_crm.projects";
    }

    /** @return HasMany<ProjectActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(ProjectActivity::class);
    }

    /** @return HasOne<ProjectActivity, $this> */
    public function latestActivity(): HasOne
    {
        return $this->hasOne(ProjectActivity::class)->latestOfMany('fecha');
    }

    /** @return HasMany<ProjectReminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(ProjectReminder::class);
    }

    /** @return HasMany<ProjectDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot  = $tenant !== null
            ? "{$tenant->slug}_crm.project_assignees"
            : 'project_assignees';

        return $this->belongsToMany(User::class, $pivot, 'project_id', 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
