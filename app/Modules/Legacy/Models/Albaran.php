<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-759 — staging de ALBVENTACAB. Llave natural compuesta de ICG:
 * (`icg_numserie`, `icg_numalbaran`, `icg_n`) — UNIQUE, el Loader-A (`AlbaranStagingLoader`) hace
 * upsert por esos 3 campos, nunca por `id`. `icg_n` es `string`, no `int` (ver docblock de la
 * migración — gotcha confirmado contra ICG real, distinto de `Pedido`/`Cotizacion`).
 *
 * `legacy_cliente_id`/`orphan` los resuelve `App\Modules\Legacy\Services\AlbaranMatcher` — nunca
 * el Loader-A (ver docblock de la migración).
 *
 * `icg_numseriefac`/`icg_numfac` — link crudo a la factura (FACTURASVENTA), sin resolver en este
 * batch (ver docblock de la migración) — se lee, nunca se interpreta.
 *
 * Sin `updated_at` — ver docblock de la migración (alcance explícito del ticket).
 *
 * @property int         $id
 * @property string      $icg_numserie
 * @property int         $icg_numalbaran
 * @property string      $icg_n
 * @property int|null    $legacy_cliente_id
 * @property int|null    $icg_codcliente
 * @property \Carbon\Carbon|null $fecha
 * @property string|null $totalneto
 * @property bool        $facturado
 * @property int|null    $icg_codvendedor
 * @property string|null $icg_numseriefac
 * @property int|null    $icg_numfac
 * @property bool        $orphan
 * @property \Carbon\Carbon|null $created_at
 * @property-read Cliente|null $cliente
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlbaranItem> $items
 */
class Albaran extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'icg_numserie', 'icg_numalbaran', 'icg_n', 'legacy_cliente_id', 'icg_codcliente',
        'fecha', 'totalneto', 'facturado', 'icg_codvendedor', 'icg_numseriefac', 'icg_numfac',
        'orphan', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha'      => 'datetime',
            'totalneto'  => 'decimal:2',
            'facturado'  => 'boolean',
            'orphan'     => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'legacy_cliente_id');
    }

    /** @return HasMany<AlbaranItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AlbaranItem::class, 'legacy_albaran_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'albaranes';
        }

        return "{$tenant->slug}_legacy.albaranes";
    }
}
