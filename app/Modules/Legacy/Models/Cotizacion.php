<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-757 — staging de PRESUPUESTOSCAB. Llave natural compuesta de ICG:
 * (`icg_numserie`, `icg_numpresupuesto`, `icg_n`, `icg_version`) — UNIQUE, el Loader-A
 * (`CotizacionStagingLoader`) hace upsert por esos 4 campos, nunca por `id`.
 *
 * `legacy_cliente_id`/`orphan` los resuelve `App\Modules\Legacy\Services\CotizacionMatcher` —
 * nunca el Loader-A (ver docblock de la migración).
 *
 * Sin `updated_at` — ver docblock de la migración (alcance explícito del ticket).
 *
 * @property int         $id
 * @property string      $icg_numserie
 * @property int         $icg_numpresupuesto
 * @property int         $icg_n
 * @property int         $icg_version
 * @property int|null    $legacy_cliente_id
 * @property int|null    $icg_codcliente
 * @property \Carbon\Carbon|null $fecha
 * @property string|null $total_neto
 * @property string|null $total_bruto
 * @property int|null    $icg_codvendedor
 * @property string|null $idestado
 * @property string|null $supresupuesto
 * @property bool        $orphan
 * @property \Carbon\Carbon|null $created_at
 * @property-read Cliente|null $cliente
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CotizacionItem> $items
 */
class Cotizacion extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'icg_numserie', 'icg_numpresupuesto', 'icg_n', 'icg_version', 'legacy_cliente_id',
        'icg_codcliente', 'fecha', 'total_neto', 'total_bruto', 'icg_codvendedor', 'idestado',
        'supresupuesto', 'orphan', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha'       => 'datetime',
            'total_neto'  => 'decimal:2',
            'total_bruto' => 'decimal:2',
            'orphan'      => 'boolean',
            'created_at'  => 'datetime',
        ];
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'legacy_cliente_id');
    }

    /** @return HasMany<CotizacionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CotizacionItem::class, 'legacy_cotizacion_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'cotizaciones';
        }

        return "{$tenant->slug}_legacy.cotizaciones";
    }
}
