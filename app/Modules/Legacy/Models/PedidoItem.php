<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-758 — staging de PEDVENTALIN. Sin llave natural propia (ver docblock de la migración) —
 * `PedidoStagingLoader` idempotiza esta tabla con un patrón delete-then-insert scoped a las
 * cabeceras de la corrida actual, no con upsert por columna única.
 *
 * @property int         $id
 * @property int         $legacy_pedido_id
 * @property int|null    $icg_codarticulo
 * @property string|null $referencia
 * @property string|null $descripcion
 * @property string|null $cantidad
 * @property string|null $precio
 * @property string|null $total
 * @property string|null $unidades_pendientes unidades aún pendientes en ICG (UNIDADESPEN)
 * @property string|null $unidades_recibidas  unidades ya servidas en ICG (UNIDADESREC)
 * @property-read Pedido $pedido
 */
class PedidoItem extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'legacy_pedido_id', 'icg_codarticulo', 'referencia', 'descripcion', 'cantidad',
        'precio', 'total', 'unidades_pendientes', 'unidades_recibidas',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:2',
            'precio'   => 'decimal:2',
            'total'    => 'decimal:2',
            'unidades_pendientes' => 'decimal:2',
            'unidades_recibidas'  => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Pedido, $this> */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'legacy_pedido_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pedido_items';
        }

        return "{$tenant->slug}_legacy.pedido_items";
    }
}
