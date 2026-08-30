<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-758 — staging de PEDVENTACAB. Llave natural compuesta de ICG:
 * (`icg_numserie`, `icg_numpedido`, `icg_n`) — UNIQUE, el Loader-A (`PedidoStagingLoader`) hace
 * upsert por esos 3 campos, nunca por `id`. A diferencia de `Cotizacion` (4 columnas, incluye
 * `icg_version`), PEDVENTACAB no versiona (ver docblock de la migración).
 *
 * `legacy_cliente_id`/`orphan` los resuelve `App\Modules\Legacy\Services\PedidoMatcher` — nunca
 * el Loader-A (ver docblock de la migración).
 *
 * `icg_seriealbaran`/`icg_numeroalbaran` — link crudo a la entrega (ALBVENTACAB), sin resolver en
 * este batch (ver docblock de la migración) — se lee, nunca se interpreta.
 *
 * Sin `updated_at` — ver docblock de la migración (alcance explícito del ticket).
 *
 * @property int         $id
 * @property string      $icg_numserie
 * @property int         $icg_numpedido
 * @property int         $icg_n
 * @property int|null    $legacy_cliente_id
 * @property int|null    $icg_codcliente
 * @property \Carbon\Carbon|null $fechapedido
 * @property \Carbon\Carbon|null $fechaentrega
 * @property string|null $totneto
 * @property int|null    $icg_codvendedor
 * @property string|null $idestado
 * @property string|null $icg_seriealbaran
 * @property int|null    $icg_numeroalbaran
 * @property bool        $orphan
 * @property \Carbon\Carbon|null $created_at
 * @property-read Cliente|null $cliente
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PedidoItem> $items
 */
class Pedido extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'icg_numserie', 'icg_numpedido', 'icg_n', 'legacy_cliente_id', 'icg_codcliente',
        'fechapedido', 'fechaentrega', 'totneto', 'icg_codvendedor', 'idestado',
        'icg_seriealbaran', 'icg_numeroalbaran', 'orphan', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fechapedido'  => 'datetime',
            'fechaentrega' => 'datetime',
            'totneto'      => 'decimal:2',
            'orphan'       => 'boolean',
            'created_at'   => 'datetime',
        ];
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'legacy_cliente_id');
    }

    /** @return HasMany<PedidoItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class, 'legacy_pedido_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pedidos';
        }

        return "{$tenant->slug}_legacy.pedidos";
    }
}
