<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 3 parte 2 (REQ-247) — producto reclamado/afectado de un ticket.
 * `marca`/`referencia`/`descripcion` son snapshot al agregar (ver docblock de la migración).
 *
 * @property int         $id
 * @property int         $ticket_id
 * @property int|null    $catalog_product_id
 * @property string|null $marca
 * @property string|null $referencia
 * @property string|null $descripcion
 * @property int         $cantidad_reclamo
 * @property int         $cantidad_recibida
 * @property int         $cantidad_pendiente
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Ticket $ticket
 * @property-read CatalogProduct|null $catalogProduct
 */
class TicketProduct extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id', 'catalog_product_id', 'marca', 'referencia', 'descripcion',
        'cantidad_reclamo', 'cantidad_recibida', 'cantidad_pendiente',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'ticket_products';
        }

        return "{$tenant->slug}_servicios.ticket_products";
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
