<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-755 — mapeo MANUAL `icg_codvendedor` → `auth.users.id`. Vacía al crearse (ver docblock de
 * la migración) — ningún `Extractor`/`Loader`/`Matcher` de este batch la puebla ni la lee todavía.
 * PK = `icg_codvendedor` (no autoincremental).
 *
 * @property int         $icg_codvendedor
 * @property int|null    $user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read User|null $user
 */
class VendedorMap extends Model
{
    protected $primaryKey = 'icg_codvendedor';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = ['icg_codvendedor', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'vendedor_map';
        }

        return "{$tenant->slug}_legacy.vendedor_map";
    }
}
