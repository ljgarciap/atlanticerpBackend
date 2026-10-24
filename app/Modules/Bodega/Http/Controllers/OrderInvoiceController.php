<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\AdminCont\Services\InvoicePdfService;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\Bodega\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * SCRUM-401 (REQ-331) — "Factura": ya no es un placeholder pasivo. Desde el Batch 2 de
 * Facturación (SCRUM-513→518, REQ-441) el módulo real de Administración & Contabilidad existe —
 * `invoice_ready` lo marca `App\Modules\Bodega\Listeners\MarkOrderInvoiceReady` al confirmarse
 * una factura. El número/monto real NO se duplica en `bodega.orders`: este controller consulta
 * `AdminContInvoice` directo por `order_id` para no mantener dos copias de la misma verdad entre
 * schemas — Bodega sigue sin generar ni calcular Subtotal/ITBMS/Total, solo lee lo que AdminCont
 * ya persistió.
 *
 * SCRUM-832 — `show()` quedó desactualizado: el frontend (`InvoiceStatusModal`) solo pintaba
 * `status_message`, nunca la factura real (ítems/Subtotal/ITBMS/Total/PDF), pese a que
 * `InvoiceService::detail()` ya arma ese payload completo desde el Batch 2. Se inyecta
 * `InvoiceService`/`InvoicePdfService` (mismos servicios que usa `AdminContInvoiceController`)
 * en vez de llamar a las rutas HTTP de `admin-contab` — esas rutas están gateadas por
 * `role:superadmin,lider_admin_contab,asistente_administrativa,management`, un universo de roles
 * distinto al de Bodega (`permission:bodega.read`), así que un usuario de Bodega sin ese rol
 * recibiría 403 si el frontend llamara directo a `/admin-contab/invoices/{id}/...`. Llamando al
 * Service directamente, el único gate de autorización sigue siendo `permission:bodega.read` en
 * las rutas de este controller — sin tocar los roles/rutas de Admin&Cont.
 */
final class OrderInvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoicePdfService $invoicePdf,
    ) {
    }

    /** GET /api/bodega/orders/{id}/invoice */
    public function show(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-929 (B3a) — la factura de ESTA entrega según la regla central (`InvoiceService::
        // invoiceForDelivery()`): su parcial vigente o, si no tiene, el `completo` vigente que la cubre.
        // Una entrega sin factura no muestra la de otra. El estado se calcula en vivo, ya no depende
        // de la columna `orders.invoice_ready`.
        $invoice = $this->invoices->invoiceForDelivery($order);

        if ($invoice === null) {
            return response()->json([
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'invoice_ready'  => false,
                'invoice_numero' => null,
                'status_message' => 'Esperando a Administración.',
            ]);
        }

        $detail = $this->invoices->detail($order->id, $invoice->id);

        return response()->json([
            ...$detail,
            'order_id'       => $order->id,
            'order_number'   => $order->order_number,
            'invoice_ready'  => true,
            'invoice_numero' => $invoice->numero,
            'status_message' => "Factura {$invoice->numero} generada por Administración & Contabilidad.",
        ]);
    }

    /** GET /api/bodega/orders/{id}/invoice/pdf — mismo documento fiscal que
     *  `AdminContInvoiceController::pdf()`, gateado por `permission:bodega.read` en vez del rol de
     *  Admin&Cont (ver docblock de la clase). */
    public function pdf(int $id): Response
    {
        $order = Order::findOrFail($id);
        $detail = $this->invoices->detail($order->id);
        $numero = $detail['numero_factura'] ?? "orden-{$order->id}";

        return $this->invoicePdf->generate($detail)->stream("factura-{$numero}.pdf");
    }
}
