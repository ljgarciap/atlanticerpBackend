<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Listeners;

use App\Modules\Bodega\Exceptions\InsufficientStockException;
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
 * RN1 (Entrada): NO se genera acá — ya la genera el flujo genérico existente
 * (`GoodsReceiptConfirmationService::confirmLine()`, disparado por
 * `PurchaseOrderController::advance()` al confirmar el Ingreso de Mercancía) para CUALQUIER orden,
 * sin importar su origen. Este listener solo reacciona cuando la orden YA está en "Recibido" —
 * la Entrada ya ocurrió, en la bodega que el usuario haya elegido al ingresar (libre, ver
 * `GoodsReceiptController::validateReceiptPayload()` — nunca forzada a una bodega en particular).
 *
 * RN2 (Reubicación hacia Reserva Servicios): 2 movimientos de Kardex simultáneos, mismo patrón
 * exacto que `RelocationRequestController::approve()` (salida del origen + entrada al destino,
 * ambos `TIPO_REUBICACION`) — PERO SOLO para la porción que no cayó ya directamente en Reserva
 * Servicios (ver fix SCRUM-837 abajo).
 *
 * **SCRUM-837 (bug real, rebote de Gerencia Test 2026-09-03)** — la versión anterior de este
 * listener asumía ciegamente que la Entrada real SIEMPRE caía en `bodegaCentralWarehouseId()`, y
 * reubicaba desde ahí incondicionalmente. Esa invariante nunca estuvo forzada en ningún lado: el
 * usuario elige libremente la bodega al ingresar, y para una orden de Servicios lo más natural es
 * elegir "Reserva Servicios" directamente (exactamente lo que hizo QA al reproducir el bug) — en
 * ese caso la Entrada YA deja el stock donde debe estar, y la Reubicación de todos modos sumaba
 * otra vez esa misma cantidad a Reserva Servicios (duplicando el stock) y restaba de Bodega
 * Central (que nunca tuvo esas unidades, dejándola en negativo). Fix: leer el `warehouse_id` REAL
 * de cada `PurchaseOrderReceiptLine` (no asumirlo) y reubicar únicamente la porción que cayó en
 * una bodega distinta de Reserva Servicios — si ya cayó en destino, no hay nada que reubicar para
 * esa porción. Esto también generaliza correctamente a cualquier otra bodega que el usuario haya
 * elegido, no solo Bodega Central.
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
 * como disponible en la bodega de origen — es justo el efecto de `KardexService::registrar()`
 * restando de una ubicación y sumando a la otra, nunca duplicando la cantidad.
 *
 * Mismo criterio defensivo que `ReleaseOrdersAwaitingStock`: un fallo acá nunca debe romper el
 * flujo de Compras que lo dispara — `handle()` completo envuelto en try/catch + `Log::error()`.
 * Adicionalmente (SCRUM-837), cada Reubicación individual también está protegida: si
 * `KardexService::registrar()` la rechaza por dejaría stock negativo en el origen
 * (`InsufficientStockException`, señal de un dato inconsistente para ESE producto puntual), se
 * loguea y se sigue con el resto de productos de la misma orden — un producto con datos
 * inconsistentes no debe bloquear la recepción completa de los demás.
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

            $destinationId = $this->insumos->reservaWarehouseId();

            // SCRUM-837 — cantidad REALMENTE recibida por producto Y por la bodega REAL donde
            // cayó cada línea (nunca asumir Bodega Central) — solo líneas de Ingreso ya
            // confirmadas (`confirmed_at` no nulo, mismo gate que
            // `GoodsReceiptConfirmationService::confirmLine()` usó para aplicar la Entrada real).
            /** @var array<int, array<int, int>> $receivedByProductAndWarehouse catalog_product_id => [warehouse_id => cantidad confirmada] */
            $receivedByProductAndWarehouse = [];
            foreach ($order->receipts as $receipt) {
                foreach ($receipt->lines as $receiptLine) {
                    if ($receiptLine->catalog_product_id === null || $receiptLine->confirmed_at === null) {
                        continue;
                    }
                    $productId = (int) $receiptLine->catalog_product_id;
                    $warehouseId = (int) $receiptLine->warehouse_id;
                    $receivedByProductAndWarehouse[$productId][$warehouseId] =
                        ($receivedByProductAndWarehouse[$productId][$warehouseId] ?? 0) + (int) $receiptLine->quantity;
                }
            }

            $referencia = "Reposición Reserva Servicios — Orden #{$order->id}";

            foreach ($receivedByProductAndWarehouse as $catalogProductId => $byWarehouse) {
                foreach ($byWarehouse as $originId => $quantity) {
                    if ($quantity <= 0) {
                        continue;
                    }

                    // SCRUM-837 — ya cayó directo en destino (el usuario eligió "Reserva
                    // Servicios" al ingresar): la Entrada real YA cumplió RN2, no hay nada que
                    // reubicar para esta porción. Reubicar de todos modos duplicaría el stock.
                    if ($originId === $destinationId) {
                        continue;
                    }

                    try {
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
                    } catch (InsufficientStockException $e) {
                        // SCRUM-837 (CA3) — nunca completar en silencio: se loguea como error y se
                        // sigue con el resto de productos de la orden, en vez de dejar toda la
                        // recepción sin marcar "recibida" por un solo producto con datos
                        // inconsistentes (ver docblock de la clase).
                        Log::error('servicios.insumo.reubicacion-stock-insuficiente', [
                            'purchase_order_id'   => $order->id,
                            'catalog_product_id'  => $catalogProductId,
                            'origin_warehouse_id' => $originId,
                            'cantidad'            => $quantity,
                            'exception'           => $e->getMessage(),
                        ]);
                    }
                }
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
