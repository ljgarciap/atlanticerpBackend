<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global (landlord-level) settings — not scoped to any tenant.
 *
 * @property string      $key
 * @property string      $value     decrypted automatically by cast
 * @property int|null    $updated_by
 * @property \Carbon\Carbon|null $updated_at
 */
class GlobalSetting extends Model
{
    public    $timestamps   = false;
    public    $incrementing = false;
    protected $table        = 'global_settings';

    /** @var string */
    protected $primaryKey = 'key';

    /** @var string */
    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
