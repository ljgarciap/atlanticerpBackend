<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-724 — override de visibilidad de un ítem de menú puntual dentro de un
 * módulo, por usuario. `menu_item_key` es una llave de string opaca — el
 * catálogo real vive solo en el frontend (src/config/menuItemCatalog.ts).
 * Solo existen filas con `visible=false`: ausencia de fila ya significa
 * visible por defecto, ver docblock de la migración.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $module
 * @property string $menu_item_key
 * @property bool   $visible
 */
class UserModuleMenuVisibility extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'module', 'menu_item_key', 'visible'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'user_module_menu_visibility' : "{$tenant->slug}_auth.user_module_menu_visibility";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
