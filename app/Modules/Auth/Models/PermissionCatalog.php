<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $key
 * @property string      $label
 * @property string      $module
 * @property string|null $description
 * @property int         $sort_order
 * @property bool        $is_active
 */
class PermissionCatalog extends Model
{
    /** @var list<string> */
    protected $fillable = ['key', 'label', 'module', 'description', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            throw new \RuntimeException('PermissionCatalog::getTable() called without an active tenant.');
        }
        return "{$tenant->slug}_auth.permission_catalog";
    }

    /**
     * Returns all active permission keys ordered by sort_order.
     *
     * @return list<string>
     */
    public static function activeKeys(): array
    {
        /** @var list<string> */
        return self::where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('key')
            ->all();
    }
}
