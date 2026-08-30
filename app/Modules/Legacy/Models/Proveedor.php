<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-756 — staging de PROVEEDORES. Llave natural de ICG: `icg_codproveedor` (UNIQUE) — el
 * Loader-A (`ProveedorStagingLoader`) hace upsert por esa columna, nunca por `id`.
 *
 * `provider_id`/`match_status` agregados 2026-08-21 (épica SCRUM-749, decisión de Luis — reabre la
 * decisión original "sin Loader-B", ver docblock de la migración
 * `2026_08_21_100000_add_provider_link_to_legacy_proveedores_table.php`) — los resuelve
 * `App\Modules\Legacy\Services\ProveedorFusionLoader`, nunca el Loader-A.
 *
 * @property int         $id
 * @property int         $icg_codproveedor
 * @property string      $nombre
 * @property string|null $nombre_comercial
 * @property string|null $cif
 * @property string|null $direccion
 * @property string|null $telefono1
 * @property string|null $email
 * @property int|null    $provider_id
 * @property string      $match_status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Provider|null $provider
 */
class Proveedor extends Model
{
    public const MATCH_UNMATCHED = 'unmatched';
    public const MATCH_MATCHED   = 'matched';
    public const MATCH_PROMOTED  = 'promoted';

    /** @var list<string> */
    protected $fillable = [
        'icg_codproveedor', 'nombre', 'nombre_comercial', 'cif', 'direccion', 'telefono1', 'email',
        'provider_id', 'match_status',
    ];

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'proveedores';
        }

        return "{$tenant->slug}_legacy.proveedores";
    }
}
