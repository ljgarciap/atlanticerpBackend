<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Listeners;

use App\Modules\AdminCont\Events\InvoiceCreated;
use App\Modules\Bodega\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-441 RN4) — reemplaza el stub `Order::invoice_ready`
 * (docblock REQ-327/328) por el estado real: al confirmarse una factura en AdminCont, este
 * listener marca el `Order` correspondiente como listo para despachar. Registrado en
 * `App\Providers\AppServiceProvider::boot()`, mismo criterio defensivo que
 * `ReleaseOrdersAwaitingStock` — un fallo acá nunca debe romper el flujo de Facturación que lo
 * dispara, por eso `handle()` queda envuelto en try/catch + `Log::error()`.
 *
 * El número de factura real NO se duplica en `bodega.orders` — `OrderInvoiceController::show()`
 * lo resuelve consultando `AdminContInvoice` directo por `order_id`, para no mantener dos copias
 * de la misma verdad entre schemas.
 */
class MarkOrderInvoiceReady
{
    public function handle(InvoiceCreated $event): void
    {
        try {
            $order = Order::find($event->orderId);
            if ($order === null) {
                return;
            }

            $order->update(['invoice_ready' => true]);
        } catch (Throwable $e) {
            Log::error('bodega.order.invoice-ready-mark-failed', [
                'order_id'   => $event->orderId,
                'invoice_id' => $event->invoiceId,
                'exception'  => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }
    }
}
