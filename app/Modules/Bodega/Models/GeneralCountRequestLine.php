<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-460/461/462 (REQ-390/391/392, Bloque B5) — un producto dentro de un conteo general.
 * `cantidad_sistema`/`cantidad_contada`/`diferencia` NULL hasta "Evaluar" (SCRUM-462, RN2 REQ-392):
 * `GeneralCountController::evaluate()` es idempotente/reemplazable — volver a llamarlo tras
 * `evaluado` recalcula estos tres campos de cero para todas las líneas.
 *
 * `replaced_by_id` (SCRUM-797, rebote 2026-08-27) — reemplazo de duplicados a nivel de LÍNEA
 * (producto), no de todo el conteo: ver docblock de `GeneralCountController::
 * findConflictingLines()`. Seteado únicamente cuando el usuario confirma reemplazar una línea
 * con diferencia real pendiente de aprobación por la línea equivalente de un conteo nuevo.
 *
 * @property int      $id
 * @property int      $general_count_request_id
 * @property int      $catalog_product_id
 * @property int|null $cantidad_sistema
 * @property int|null $cantidad_contada
 * @property int|null $diferencia
 * @property int|null $replaced_by_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read GeneralCountRequest $request
 * @property-read CatalogProduct|null $catalogProduct
 * @property-read GeneralCountRequestLine|null $replacedBy
 */
class GeneralCountRequestLine extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'general_count_request_id', 'catalog_product_id',
        'cantidad_sistema', 'cantidad_contada', 'diferencia', 'replaced_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad_sistema' => 'integer',
            'cantidad_contada' => 'integer',
            'diferencia' => 'integer',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'general_count_request_lines';
        }

        return "{$tenant->slug}_bodega.general_count_request_lines";
    }

    /** @return BelongsTo<GeneralCountRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(GeneralCountRequest::class, 'general_count_request_id');
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<GeneralCountRequestLine, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
