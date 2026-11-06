<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 27c — código de respaldo de un solo uso del MFA con aplicación autenticadora. En BD solo vive su huella (HMAC-SHA256).
 *
 * @property int                 $id
 * @property int                 $user_id
 * @property string              $code_hash
 * @property \Carbon\Carbon|null $used_at
 */
class MfaBackupCode extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'mfa_backup_codes' : "{$tenant->slug}_auth.mfa_backup_codes";
    }
}
