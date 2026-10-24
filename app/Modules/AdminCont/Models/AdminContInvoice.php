<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Modules\Bodega\Models\Order;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441). Ver docblock de la migración
 * `create_admin_contab_invoices_table` para el detalle de `order_id`/`es_incobrable`.
 *
 * SCRUM-876 → SCRUM-929 — un PEDIDO es su tarjeta raíz (`Order::familyRootId()`, columna
 * `family_root_id`). Una factura `tipo = completo` cuelga de la raíz (`order_id` = raíz) y cubre el
 * pedido entero; una `tipo = parcial` cuelga de la ENTREGA que la originó (`order_id` = esa
 * tarjeta). Por eso un pedido puede tener VARIAS facturas activas: todo lookup del pedido debe
 * pasar por `forOrder()` y decidir cuál factura necesita (por `tipo`/`order_id`), nunca `->first()`
 * a ciegas ni `where('order_id', ...)` con el id de una entrega cualquiera.
 *
 * @property int         $id
 * @property string       $numero
 * @property int           $order_id
 * @property int           $family_root_id
 * @property string        $tipo
 * @property int|null      $quote_id
 * @property int|null      $itbms_rate_id
 * @property int|null      $sales_project_id
 * @property int|null      $master_client_id
 * @property string        $cliente
 * @property float          $monto
 * @property float           $saldo_aplicado
 * @property float            $ajuste_itbms_delta
 * @property bool            $es_incobrable
 * @property string|null    $motivo_incobrable
 * @property bool            $incobrable_pendiente
 * @property string|null    $serie
 * @property string|null    $fe_estado
 * @property string|null    $fe_detalle
 * @property string|null    $cufe
 * @property string|null    $qr_content
 * @property string|null    $xml_firmado_path
 * @property string|null    $pac_provider_usado
 * @property \Carbon\Carbon|null $fe_certificado_at
 * @property \Carbon\Carbon|null $fe_emision_at  SCRUM-1019 — fecha de emisión fijada en el primer envío al PAC
 * @property string|null    $receptor_tipo  SCRUM-1140 — receptor congelado (`01`/`02`); NULL = factura anterior, el builder lee el cliente en vivo
 * @property string|null    $receptor_ruc
 * @property string|null    $receptor_dv
 * @property string|null    $receptor_nombre  razón social validada por la DGI (o la de AtlanticERP si no hubo validación)
 * @property \Carbon\Carbon|null $receptor_validado_at
 * @property \Carbon\Carbon|null $anulada_at
 * @property int|null      $created_by
 * @property \Carbon\Carbon $created_at
 * @property-read AdminContItbmsRate|null $itbmsRate
 */
class AdminContInvoice extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /**
     * SCRUM-929 — `parcial`: factura de UNA entrega (`order_id` = esa tarjeta). `completo`: factura
     * del pedido entero (`order_id` = tarjeta raíz). Toda factura previa a SCRUM-929 es `completo`.
     */
    public const TIPO_PARCIAL  = 'parcial';
    public const TIPO_COMPLETO = 'completo';

    /** Texto del rechazo del PAC: solo se expone vía los controllers de detalle, gateado a Gerencia/superadmin. @var list<string> */
    protected $hidden = ['fe_detalle'];

    /** @var list<string> */
    protected $fillable = [
        'numero', 'order_id', 'family_root_id', 'tipo', 'quote_id', 'itbms_rate_id', 'sales_project_id', 'master_client_id',
        'cliente', 'monto', 'saldo_aplicado', 'ajuste_itbms_delta', 'es_incobrable', 'motivo_incobrable',
        'incobrable_pendiente', 'anulada_at', 'created_by',
        'receptor_tipo', 'receptor_ruc', 'receptor_dv', 'receptor_nombre', 'receptor_validado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto'              => 'float',
            'saldo_aplicado'     => 'float',
            'ajuste_itbms_delta' => 'float',
            'es_incobrable'  => 'boolean',
            'incobrable_pendiente' => 'boolean',
            'anulada_at'     => 'datetime',
            'fe_emision_at'  => 'datetime',
            'receptor_validado_at' => 'datetime',
            'created_at'     => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'invoices';
        }

        return "{$tenant->slug}_admin_contab.invoices";
    }

    /**
     * SCRUM-929 — `family_root_id` es NOT NULL; ~22 archivos crean facturas con `order_id` y nada
     * más, así que se resuelve acá (raíz de la familia de la tarjeta, o el propio `order_id` si la
     * tarjeta no existe). Un valor explícito nunca se pisa.
     */
    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if ($invoice->family_root_id === null) {
                $invoice->family_root_id = Order::find($invoice->order_id)?->familyRootId() ?? $invoice->order_id;
            }
        });
    }

    /**
     * SCRUM-876/929 — query de TODAS las facturas de un PEDIDO a partir de cualquiera de sus
     * entregas. Resuelve la entrega a su tarjeta raíz (`Order::familyRootId()`) y filtra por
     * `family_root_id` (antes por `order_id`: desde SCRUM-929 una factura `parcial` cuelga de la
     * entrega que la originó, no de la raíz). Con pedidos de una sola factura — todo lo anterior a
     * SCRUM-929 — el resultado es idéntico. Puede devolver varias filas: un llamador que asuma UNA
     * factura debe decidir cuál (por `tipo`/`order_id`), nunca `->first()` a ciegas.
     * No filtra `anulada_at` — el llamador decide (una anulada + su reemplazo conviven, ver
     * migración `relax_order_id_unique_on_admin_contab_invoices_table`).
     *
     * @return Builder<self>
     */
    public static function forOrder(Order|int $order): Builder
    {
        $rootId = $order instanceof Order
            ? $order->familyRootId()
            : (Order::find($order)?->familyRootId() ?? $order);

        return self::query()->where('family_root_id', $rootId);
    }

    /**
     * SCRUM-929 — líneas facturadas (snapshot), ver migración `create_admin_contab_invoice_lines_table`.
     *
     * @return HasMany<AdminContInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AdminContInvoiceLine::class, 'invoice_id');
    }

    /**
     * Batch 10 (REQ-480) — tasa de ITBMS aplicada al facturar.
     * @return BelongsTo<AdminContItbmsRate, $this>
     */
    public function itbmsRate(): BelongsTo
    {
        return $this->belongsTo(AdminContItbmsRate::class, 'itbms_rate_id');
    }
}
