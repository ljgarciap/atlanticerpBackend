<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-840 — staging de ALBCOMPRALIN. Sin llave natural propia — `AlbaranCompraStagingLoader`
 * idempotiza con delete-then-insert scoped a las cabeceras de la corrida actual, mismo criterio
 * que `AlbaranItem`.
 *
 * @property int         $id
 * @property int         $legacy_albaran_compra_id
 * @property int|null    $icg_codarticulo
 * @property string|null $referencia
 * @property string|null $descripcion
 * @property string|null $cantidad
 * @property string|null $precio
 * @property string|null $total
 * @property-read AlbaranCompra $albaranCompra
 */
class AlbaranCompraItem extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'legacy_albaran_compra_id', 'icg_codarticulo', 'referencia', 'descripcion', 'cantidad',
        'precio', 'total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'precio'   => 'decimal:2',
            'total'    => 'decimal:2',
        ];
    }

    /** @return BelongsTo<AlbaranCompra, $this> */
    public function albaranCompra(): BelongsTo
    {
        return $this->belongsTo(AlbaranCompra::class, 'legacy_albaran_compra_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'albaran_compra_items';
        }

        return "{$tenant->slug}_legacy.albaran_compra_items";
    }
}
