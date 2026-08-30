<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * SCRUM-401 (REQ-331) — "Factura": ya no es un placeholder pasivo. Desde el Batch 2 de
 * Facturación (SCRUM-513→518, REQ-441) el módulo real de Administración & Contabilidad existe —
 * `invoice_ready` lo marca `App\Modules\Bodega\Listeners\MarkOrderInvoiceReady` al confirmarse
 * una factura. El número/monto real NO se duplica en `bodega.orders`: este controller consulta
 * `AdminContInvoice` directo por `order_id` para no mantener dos copias de la misma verdad entre
 * schemas — Bodega sigue sin generar ni calcular Subtotal/ITBMS/Total, solo lee lo que AdminCont
 * ya persistió.
 */
final class OrderInvoiceController extends Controller
{
    /** GET /api/bodega/orders/{id}/invoice */
    public function show(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $invoice = $order->invoice_ready ? AdminContInvoice::where('order_id', $order->id)->first() : null;

        return response()->json([
            'order_id'        => $order->id,
            'order_number'    => $order->order_number,
            'invoice_ready'   => $order->invoice_ready,
            'invoice_numero'  => $invoice?->numero,
            'status_message'  => $invoice !== null
                ? "Factura {$invoice->numero} generada por Administración & Contabilidad."
                : 'Esperando a Administración.',
        ]);
    }
}
