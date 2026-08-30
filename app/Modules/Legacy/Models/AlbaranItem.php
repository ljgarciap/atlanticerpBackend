<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-759 — staging de ALBVENTALIN. Sin llave natural propia (ver docblock de la migración) —
 * `AlbaranStagingLoader` idempotiza esta tabla con un patrón delete-then-insert scoped a las
 * cabeceras de la corrida actual, no con upsert por columna única.
 *
 * @property int         $id
 * @property int         $legacy_albaran_id
 * @property int|null    $icg_codarticulo
 * @property string|null $referencia
 * @property string|null $descripcion
 * @property string|null $cantidad
 * @property string|null $precio
 * @property string|null $total
 * @property-read Albaran $albaran
 */
class AlbaranItem extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'legacy_albaran_id', 'icg_codarticulo', 'referencia', 'descripcion', 'cantidad',
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

    /** @return BelongsTo<Albaran, $this> */
    public function albaran(): BelongsTo
    {
        return $this->belongsTo(Albaran::class, 'legacy_albaran_id');
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'albaran_items';
        }

        return "{$tenant->slug}_legacy.albaran_items";
    }
}
