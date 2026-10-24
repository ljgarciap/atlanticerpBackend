<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Listeners;

use App\Modules\AdminCont\Events\InvoiceCreated;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-441 RN4) — antes marcaba `orders.invoice_ready = true`
 * al confirmarse una factura.
 *
 * SCRUM-876 — `orders.invoice_ready` deja de usarse: la factura vive siempre en la tarjeta RAÍZ
 * del pedido, y "¿este pedido está facturado?" se calcula EN VIVO vía
 * `AdminContInvoice::forOrder($order)` (ver `OrderInvoiceController::show()` y
 * `OrderBoardService::invoicedRootIds()`). Con una familia de entregas parciales, marcar la
 * columna por-fila se desincronizaba (una entrega hermana que llega a "Por despachar" DESPUÉS de
 * generada la factura nunca se marcaba). El cálculo en vivo lo resuelve sin estado duplicado.
 *
 * El listener se deja registrado (en `AppServiceProvider::boot()`) como no-op para no tocar ese
 * wiring en este batch — la columna `orders.invoice_ready` y este archivo se pueden eliminar en
 * una limpieza posterior.
 */
class MarkOrderInvoiceReady
{
    public function handle(InvoiceCreated $event): void
    {
        // no-op — ver docblock (SCRUM-876).
    }
}
