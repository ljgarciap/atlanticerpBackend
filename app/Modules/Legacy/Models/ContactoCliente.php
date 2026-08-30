<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-755 — staging de CONTACTOSCLIENTES. Llave natural de ICG: `icg_id` (UNIQUE, la PK real de
 * CONTACTOSCLIENTES) — el Loader-A hace upsert por esa columna, nunca por `id`.
 *
 * @property int         $id
 * @property int         $legacy_cliente_id
 * @property int         $icg_id
 * @property string|null $cargo
 * @property string      $nombre
 * @property string|null $telefono
 * @property string|null $mobil
 * @property string|null $email
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Cliente $cliente
 */
class ContactoCliente extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'legacy_cliente_id', 'icg_id', 'cargo', 'nombre', 'telefono', 'mobil', 'email',
    ];

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'legacy_cliente_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'contactos_cliente';
        }

        return "{$tenant->slug}_legacy.contactos_cliente";
    }
}
