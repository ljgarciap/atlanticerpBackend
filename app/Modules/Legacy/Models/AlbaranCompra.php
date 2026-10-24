<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-840 — staging de ALBCOMPRACAB. Llave natural compuesta: (`icg_numserie`, `icg_numalbaran`,
 * `icg_n`) — UNIQUE, upsert por esos 3 campos (`AlbaranCompraStagingLoader`), nunca por `id`.
 *
 * `legacy_proveedor_id`/`orphan` los resuelve `App\Modules\Legacy\Services\AlbaranCompraMatcher` —
 * nunca el Loader-A.
 *
 * @property int         $id
 * @property string      $icg_numserie
 * @property int         $icg_numalbaran
 * @property string      $icg_n
 * @property int|null    $legacy_proveedor_id
 * @property int|null    $icg_codproveedor
 * @property \Carbon\Carbon|null $fecha
 * @property string|null $totalneto
 * @property bool        $facturado
 * @property string|null $icg_numseriefac
 * @property int|null    $icg_numfac
 * @property bool        $orphan
 * @property \Carbon\Carbon|null $created_at
 * @property-read Proveedor|null $proveedor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlbaranCompraItem> $items
 */
class AlbaranCompra extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'icg_numserie', 'icg_numalbaran', 'icg_n', 'legacy_proveedor_id', 'icg_codproveedor',
        'fecha', 'totalneto', 'facturado', 'icg_numseriefac', 'icg_numfac', 'orphan', 'created_at',
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

    /** @return BelongsTo<Proveedor, $this> */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'legacy_proveedor_id');
    }

    /** @return HasMany<AlbaranCompraItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AlbaranCompraItem::class, 'legacy_albaran_compra_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'albaranes_compra';
        }

        return "{$tenant->slug}_legacy.albaranes_compra";
    }
}
