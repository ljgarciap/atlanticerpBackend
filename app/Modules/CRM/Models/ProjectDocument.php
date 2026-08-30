<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int             $id
 * @property int             $project_id
 * @property string          $categoria
 * @property string          $nombre_archivo
 * @property string          $storage_key
 * @property int             $size_bytes
 * @property string          $mime_type
 * @property string          $storage_zone   'public'|'private'
 * @property string|null     $public_url
 * @property int             $version
 * @property int|null        $parent_document_id
 * @property bool            $is_current
 * @property int             $uploaded_by
 * @property \Carbon\Carbon  $created_at
 * @property Project         $project
 * @property User            $uploader
 * @property self|null       $parent
 * @property \Illuminate\Database\Eloquent\Collection<int, self> $versions
 */
class ProjectDocument extends Model
{
    public const UPDATED_AT = null;

    public const ZONE_PRIVATE = 'private';
    public const ZONE_PUBLIC  = 'public';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'categoria',
        'nombre_archivo',
        'storage_key',
        'size_bytes',
        'mime_type',
        'storage_zone',
        'public_url',
        'version',
        'parent_document_id',
        'is_current',
        'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'is_current' => 'boolean',
            'version'    => 'integer',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'project_documents';
        }

        return "{$tenant->slug}_crm.project_documents";
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    /** @return HasMany<self, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_document_id');
    }

    public function diskName(): string
    {
        return $this->storage_zone === self::ZONE_PUBLIC ? 's3-public' : 's3-private';
    }
}
