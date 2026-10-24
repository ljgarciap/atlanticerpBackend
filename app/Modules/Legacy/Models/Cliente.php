<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-755 — staging de CLIENTES. Llave natural de ICG: `icg_codcliente` (UNIQUE) — el Loader-A
 * (`ClienteStagingLoader`) hace upsert por esa columna, nunca por `id`.
 *
 * `sub_client_id`/`match_status` los resuelve `App\Modules\Legacy\Services\ClienteMatcher` — nunca
 * el Loader-A (ver docblock de la migración).
 *
 * @property int         $id
 * @property int         $icg_codcliente
 * @property string|null $nombre_comercial
 * @property string      $razon_social
 * @property string|null $cif
 * @property string|null $nif20       SCRUM-1137 — texto crudo de CLIENTES.NIF20
 * @property string|null $ruc         SCRUM-1137 — RUC normalizado (TaxIdNormalizer)
 * @property string|null $ruc_dv
 * @property string|null $ruc_origen  nif20 | cif | ninguno
 * @property string|null $ruc_estado  ok | truncado_posible | revisar
 * @property string|null $direccion
 * @property string|null $poblacion
 * @property string|null $provincia
 * @property string|null $pais
 * @property string|null $telefono1
 * @property string|null $telefono2
 * @property string|null $mobil
 * @property string|null $email
 * @property int|null    $icg_codvendedor
 * @property int|null    $sub_client_id
 * @property string      $match_status
 * @property bool        $bloqueado
 * @property \Carbon\Carbon|null $fecha_modificado_icg
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read SubClient|null $subClient
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ContactoCliente> $contactos
 */
class Cliente extends Model
{
    public const MATCH_MATCHED   = 'matched';
    public const MATCH_UNMATCHED = 'unmatched';
    public const MATCH_AMBIGUOUS = 'ambiguous';
    /** Épica SCRUM-749, 2026-08-21 — seteado por `App\Modules\Legacy\Services\ClienteFusionLoader`
     * cuando crea un `sub_client` nuevo para un cliente recurrente sin match real por CIF/email. */
    public const MATCH_PROMOTED  = 'promoted';

    /** @var list<string> */
    protected $fillable = [
        'icg_codcliente', 'nombre_comercial', 'razon_social', 'cif', 'direccion', 'poblacion',
        'provincia', 'pais', 'telefono1', 'telefono2', 'mobil', 'email', 'icg_codvendedor',
        'sub_client_id', 'match_status', 'bloqueado', 'fecha_modificado_icg',
        'nif20', 'ruc', 'ruc_dv', 'ruc_origen', 'ruc_estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bloqueado'             => 'boolean',
            'fecha_modificado_icg'  => 'datetime',
        ];
    }

    /** @return BelongsTo<SubClient, $this> */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class);
    }

    /** @return HasMany<ContactoCliente, $this> */
    public function contactos(): HasMany
    {
        return $this->hasMany(ContactoCliente::class, 'legacy_cliente_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'clientes';
        }

        return "{$tenant->slug}_legacy.clientes";
    }
}
