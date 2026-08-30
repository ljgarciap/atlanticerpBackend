<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $nombre
 * @property string      $tipo
 * @property string|null $email
 * @property string|null $telefono
 * @property string|null $empresa
 * @property string|null $notas
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Contact extends Model
{
    public const TYPE_DEVELOPER  = 'developer';
    public const TYPE_ARQUITECTO = 'arquitecto';
    public const TYPE_ENCARGADO  = 'encargado';
    public const TYPE_CONTACTO   = 'contacto';

    public const ALLOWED_TYPES = [
        self::TYPE_DEVELOPER,
        self::TYPE_ARQUITECTO,
        self::TYPE_ENCARGADO,
        self::TYPE_CONTACTO,
    ];

    /** @var list<string> */
    protected $fillable = ['nombre', 'tipo', 'email', 'telefono', 'empresa', 'notas'];

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'contacts';
        }

        return "{$tenant->slug}_crm.contacts";
    }
}
