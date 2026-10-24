<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services\Billing;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use Illuminate\Support\Collection;

/**
 * SCRUM-929 (B2) — foto de UN pedido (familia de entregas) en el momento de facturar: qué tarjetas
 * tiene, qué ya se facturó (solo facturas ACTIVAS — una anulada devuelve su saldo) y contra qué
 * cotización se calcula. Es el insumo único de `InvoiceBillingPlanner` y de las proyecciones de
 * lectura de `InvoiceService`; para el guard transaccional se construye DENTRO de la transacción,
 * ya con el lock de la fila raíz tomado, para no decidir sobre datos viejos.
 *
 * `linkable` = todo `OrderItem` del pedido tiene `quote_item_id` y ese `QuoteItem` pertenece a la
 * cotización vigente del pedido. Si es false, el pedido solo admite facturación `completo`
 * (degradación segura del ADR §1): sin el vínculo no hay forma de saber cuánto de cada ítem
 * cotizado salió en cada entrega, y facturar "por lo entregado" sería adivinar.
 *
 * SCRUM-929 (B3b Punto 4) — `total` YA incluye `ajusteItbms` (Σ `ajuste_itbms_delta` de las
 * facturas activas): es el total "ajustado" contra el que se guarda/cierra el pedido, no
 * `pipeline_cards.amount` crudo — ver `InvoiceBillingPlanner::context()`. `ajusteItbms` se expone
 * aparte porque `cerrarPedido()` lo necesita también como ajuste del OBJETIVO de ITBMS que
 * reconcilia el residual de redondeo (`Quote::itbms() + ajusteItbms`), no solo del total.
 */
final class BillingContext
{
    /**
     * @param Collection<int, Order>            $members    raíz + hijas (con `items`), en orden de secuencia
     * @param Collection<int, AdminContInvoice> $active     facturas activas del pedido (con `lines`)
     * @param Collection<int, QuoteItem>        $quoteItems ítems de la cotización vigente, indexados por id
     */
    public function __construct(
        public readonly Order $root,
        public readonly ?Quote $quote,
        public readonly float $total,
        public readonly Collection $members,
        public readonly Collection $active,
        public readonly Collection $quoteItems,
        public readonly bool $linkable,
        public readonly float $ajusteItbms = 0.0,
    ) {}

    /** Un pedido de entrega única (una sola tarjeta): "entrega" y "pedido completo" son lo mismo. */
    public function esEntregaUnica(): bool
    {
        return $this->members->count() <= 1;
    }

    /** Σ de las facturas activas del pedido (parciales + completo). */
    public function facturado(): float
    {
        return round((float) $this->active->sum('monto'), 2);
    }

    /** Saldo del total de la cotización todavía sin facturar (nunca negativo). */
    public function restante(): float
    {
        return max(0.0, round($this->total - $this->facturado(), 2));
    }

    public function completoActivo(): ?AdminContInvoice
    {
        return $this->active->first(fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_COMPLETO);
    }

    public function parcialActivaDe(int $orderId): ?AdminContInvoice
    {
        return $this->active->first(
            fn (AdminContInvoice $i): bool => $i->tipo === AdminContInvoice::TIPO_PARCIAL && (int) $i->order_id === $orderId,
        );
    }

    /**
     * Cantidad ya facturada por `quote_item_id`, sumando las líneas de las facturas activas. Es la
     * llave del "cuánto se facturó" — lo que impide cobrar dos veces la misma mercadería al combinar
     * facturas por entrega y "pedido completo".
     *
     * @return array<int, float>
     */
    public function cantidadFacturada(): array
    {
        $billed = [];
        foreach ($this->active as $invoice) {
            foreach ($invoice->lines as $line) {
                if ($line->quote_item_id !== null) {
                    $billed[$line->quote_item_id] = ($billed[$line->quote_item_id] ?? 0.0) + $line->cantidad;
                }
            }
        }

        return $billed;
    }

    public function entrega(int $orderId): ?Order
    {
        return $this->members->first(fn (Order $o): bool => $o->id === $orderId);
    }
}
