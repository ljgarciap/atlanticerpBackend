<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Listeners;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Compras\Events\PurchaseOrderReceived;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Servicios\Models\InsumoPurchaseRequest;
use App\Modules\Servicios\Services\InsumoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-277, SCRUM-347) — puente Compras -> Servicios, mismo
 * mecanismo exacto que `App\Modules\Bodega\Listeners\ReleaseOrdersAwaitingStock` (mismo evento,
 * registrado como segundo listener en `AppServiceProvider::boot()`).
 *
 * RN1 (Entrada a Bodega Central): NO se genera acá — ya la genera el flujo genérico existente
 * (`GoodsReceiptConfirmationService::confirmLine()`, disparado por
 * `PurchaseOrderController::advance()` al confirmar el Ingreso de Mercancía) para CUALQUIER orden,
 * sin importar su origen. Este listener solo reacciona cuando la orden YA está en "Recibido" —
 * la Entrada ya ocurrió.
 *
 * RN2 (Reubicación, Bodega Central -> Reserva Servicios): 2 movimientos de Kardex simultáneos,
 * mismo patrón exacto que `RelocationRequestController::approve()` (salida del origen + entrada al
 * destino, ambos `TIPO_REUBICACION`).
 *
 * Senior Review 2026-08-13 (Arquitecto) — endurecido para leer la cantidad reubicada de
 * `PurchaseOrderReceiptLine.quantity` (confirmada, agregada por TODOS los recibos de la orden —
 * `GoodsReceiptController` permite más de una recepción parcial por orden, REQ-165/166) en vez de
 * `PurchaseOrder->lines` (`PurchaseOrderLine.quantity`, lo ORDENADO). Investigado a fondo si esto
 * era explotable con una recepción parcial que nunca se completa: NO lo es hoy —
 * `PurchaseOrderController::advance()` bloquea la transición a "Recibido" mientras
 * `pending_remainder_status` siga activo, y ese campo solo se limpia cuando lo recibido alcanza lo
 * ordenado (ver `GoodsReceiptController::saveReceipt()`) — así que al momento en que este listener
 * corre, recibido==ordenado siempre, por diseño de ese gate. Se mantiene igual la lectura desde
 * recibos (no desde lo ordenado) por consistencia con la misma fuente de verdad que ya usa
 * `GoodsReceiptConfirmationService::confirmLine()` para la Entrada real, y como defensa si ese gate
 * cambia en el futuro — no por un bug reproducible hoy.
 *
 * RN3 (mismo momento): la Reubicación y el paso a "recibida" de la `InsumoPurchaseRequest` local
 * ocurren en la MISMA transacción — no hay ventana donde uno ya pasó y el otro no.
 *
 * RN4 (independencia del stock general de Bodega): una vez reubicado, ese stock deja de contar
 * como disponible en Bodega Central — es justo el efecto de `KardexService::registrar()` restando
 * de una ubicación y sumando a la otra, nunca duplicando la cantidad.
 *
 * Mismo criterio defensivo que `ReleaseOrdersAwaitingStock`: un fallo acá nunca debe romper el
 * flujo de Compras que lo dispara — `handle()` completo envuelto en try/catch + `Log::error()`.
 */
class SyncInsumoReservationOnPurchaseOrderReceived
{
    public function __construct(
        private readonly KardexService $kardex,
        private readonly InsumoService $insumos,
    ) {}

    public function handle(PurchaseOrderReceived $event): void
    {
        try {
            $this->sync($event);
        } catch (Throwable $e) {
            Log::error('servicios.insumo.reservation-sync-failed', [
                'purchase_order_id' => $event->purchaseOrderId,
                'tenant'            => $event->tenant,
                'exception'         => $e->getMessage(),
                'exception_class'   => $e::class,
            ]);
        }
    }

    private function sync(PurchaseOrderReceived $event): void
    {
        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::with('receipts.lines')->find($event->purchaseOrderId);
        if ($order === null || $order->origin_module !== PurchaseOrder::ORIGIN_SERVICIOS) {
            return;
        }

        DB::transaction(function () use ($order): void {
            /** @var \Illuminate\Database\Eloquent\Collection<int, InsumoPurchaseRequest> $requests */
            $requests = InsumoPurchaseRequest::query()
                ->where('purchase_order_id', $order->id)
                ->where('estado', InsumoPurchaseRequest::ESTADO_PENDIENTE)
                ->lockForUpdate()
                ->get();

            if ($requests->isEmpty()) {
                // Ya procesado por una llamada concurrente anterior, o esta orden no tiene ninguna
                // solicitud de Servicios pendiente asociada (defensivo — no debería pasar para una
                // orden origin_module=servicios, pero nunca se asume).
                return;
            }

            $originId      = $this->insumos->bodegaCentralWarehouseId();
            $destinationId = $this->insumos->reservaWarehouseId();

            // Cantidad REALMENTE recibida por producto — solo líneas de Ingreso ya confirmadas
            // (`confirmed_at` no nulo, mismo gate que `GoodsReceiptConfirmationService::
            // confirmLine()` usó para aplicar la Entrada real) — nunca la cantidad ordenada.
            /** @var array<int, int> $receivedByProduct catalog_product_id => cantidad confirmada */
            $receivedByProduct = [];
            foreach ($order->receipts as $receipt) {
                foreach ($receipt->lines as $receiptLine) {
                    if ($receiptLine->catalog_product_id === null || $receiptLine->confirmed_at === null) {
                        continue;
                    }
                    $productId = (int) $receiptLine->catalog_product_id;
                    $receivedByProduct[$productId] = ($receivedByProduct[$productId] ?? 0) + (int) $receiptLine->quantity;
                }
            }

            $referencia = "Reposición Reserva Servicios — Orden #{$order->id}";

            foreach ($receivedByProduct as $catalogProductId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                $this->kardex->registrar(
                    catalogProductId: $catalogProductId,
                    warehouseId: $originId,
                    tipo: InventoryMovement::TIPO_REUBICACION,
                    cantidad: -$quantity,
                    referenciaTexto: $referencia,
                    referenciaEntidadTipo: 'SERVICIOS_INSUMO_PURCHASE_REQUEST',
                    referenciaEntidadId: $order->id,
                );
                $this->kardex->registrar(
                    catalogProductId: $catalogProductId,
                    warehouseId: $destinationId,
                    tipo: InventoryMovement::TIPO_REUBICACION,
                    cantidad: $quantity,
                    referenciaTexto: $referencia,
                    referenciaEntidadTipo: 'SERVICIOS_INSUMO_PURCHASE_REQUEST',
                    referenciaEntidadId: $order->id,
                );
            }

            foreach ($requests as $request) {
                $request->update([
                    'estado'      => InsumoPurchaseRequest::ESTADO_RECIBIDA,
                    'received_at' => now(),
                ]);
            }
        });
    }
}
