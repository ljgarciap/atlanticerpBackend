<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-245→249 (REQ-182→186) — solicitud de sustituto. Ver docblock de la migración: este batch
 * (SCRUM-248/249) solo consume `status`/`original_catalog_product_id`/`generated_order_id` —
 * `proposed_catalog_product_id`/`margin_percent`/`approved_by` los llena la Oleada 2 (REQ-182→184).
 *
 * @property int         $id
 * @property int         $original_catalog_product_id
 * @property int|null    $sales_project_id
 * @property int|null    $proposed_catalog_product_id
 * @property float|null  $margin_percent
 * @property string      $status
 * @property int|null    $requested_by
 * @property int|null    $approved_by
 * @property int|null    $generated_order_id
 * @property \Carbon\Carbon $created_at
 */
class ReplacementRequest extends Model
{
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_PENDIENTE_GERENCIA = 'pendiente_gerencia';
    public const STATUS_APROBADA = 'aprobada';
    public const STATUS_RECHAZADA = 'rechazada';

    /** @var list<string> */
    protected $fillable = [
        'original_catalog_product_id', 'sales_project_id', 'proposed_catalog_product_id',
        'margin_percent', 'status', 'requested_by', 'approved_by', 'generated_order_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['margin_percent' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'replacement_requests';
        }

        return "{$tenant->slug}_compras.replacement_requests";
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'original_catalog_product_id');
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function proposedProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'proposed_catalog_product_id');
    }

    /** @return BelongsTo<SalesProject, $this> */
    public function salesProject(): BelongsTo
    {
        return $this->belongsTo(SalesProject::class, 'sales_project_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function generatedOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'generated_order_id');
    }
}
