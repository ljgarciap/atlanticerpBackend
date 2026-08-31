<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Services\KardexService;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-173/REQ-142 — aplica el efecto de stock de confirmar una línea "Por ingresar", y confirma
 * en lote. Extraído de `GoodsReceiptController` (fix Pre-QA 2026-07-20, SCRUM-205) para
 * reusarlo byte-a-byte desde `PurchaseOrderController::advance()`: el docblock de `advance()`
 * decía explícitamente que la auto-confirmación al llegar a "Recibido" (REQ-142 Escenario 1)
 * quedaba diferida porque Ingreso de Mercancía no existía — ya existe (SCRUM-220→230), pero
 * nadie había vuelto a conectar ambos lados.
 */
final class GoodsReceiptConfirmationService
{
    public function __construct(private readonly KardexService $kardex) {}

    /**
     * SCRUM-347 (rebote QA 2026-08-14) — `PurchaseOrderController::advance()` solo bloqueaba el
     * paso a "Recibido" cuando `pending_remainder_status` ya estaba seteado (REQ-165, remanente
     * de una recepción PARCIAL). Si nunca se registró ningún Ingreso de Mercancía para la orden
     * (`purchase_order_receipts` vacío), ese campo nunca se toca y queda NULL para siempre — la
     * orden avanzaba libremente hasta "Recibido" sin que nadie confirmara una sola línea, sin
     * movimiento de Kardex y sin ningún error visible (reproducido por QA con 2 órdenes reales,
     * 20 unidades "recibidas" que no existen en ninguna ubicación). Este método es la fuente única
     * para "¿esta orden ya recibió TODO lo que pidió?", usada tanto acá como en
     * `GoodsReceiptController::receivedByProduct()`.
     *
     * @return Collection<int, int> cantidad recibida por catalog_product_id
     */
    public function receivedQuantitiesByProduct(PurchaseOrder $order, ?int $excludeReceiptId = null): Collection
    {
        return PurchaseOrderReceiptLine::query()
            ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $order->id)
                ->when($excludeReceiptId !== null, fn ($q2) => $q2->where('id', '!=', $excludeReceiptId)))
            ->get()
            ->groupBy('catalog_product_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));
    }

    /** SCRUM-347 — true solo si cada producto esperado ya recibió, al menos, la cantidad pedida. */
    public function isFullyReceived(PurchaseOrder $order): bool
    {
        $received = $this->receivedQuantitiesByProduct($order);
        // SCRUM-347 seguimiento (rebote QA 2026-08-19): excluir líneas con catalog_product_id NULL
        // (producto catalogado borrado tras crear la orden, FK nullOnDelete) antes de groupBy —
        // sin el filtro, PHP agrupa ese valor bajo la key string "" y el closure tipado de abajo
        // tira TypeError apenas una orden trae una línea así (tumbaba eligible-orders completo).
        // Mismo criterio ya usado en PurchaseOrderController::advance() y
        // GoodsReceiptController::orderExpectedProducts().
        $expected = $order->lines
            ->filter(fn ($line) => $line->catalog_product_id !== null)
            ->groupBy('catalog_product_id')
            ->map(fn ($lines) => (int) $lines->sum('quantity'));

        return $expected->every(fn (int $qty, int $productId) => ($received[$productId] ?? 0) >= $qty);
    }

    /**
     * Aplica el efecto de stock de una línea ya confirmada (recién guardada o recién
     * confirmada).
     *
     * Hallazgo de Pre-QA 2026-07-20 (SCRUM-205, coincide con lo que marly veía como "stock
     * nulo" en sus 3 rechazos previos): `catalog_products.stock_quantity` es nullable a
     * propósito ("disponibilidad no controlada", ver migración `2026_07_19_100002`) — un
     * producto creado desde Nueva Orden (`PurchaseOrderController::createProduct()`) nunca lo
     * inicializa, queda NULL. `increment()` genera `SET stock_quantity = stock_quantity + N`,
     * que en SQL evalúa `NULL + N = NULL` — el incremento nunca aplicaba, para NINGÚN producto
     * pedido vía Nueva Orden, sin importar cuántas confirmaciones se hicieran. La recepción real
     * de mercancía es la señal inequívoca de que ese producto pasa a estar trackeado desde acá en
     * adelante, sin importar cómo se creó — por eso el COALESCE, no un fix en `createProduct()`
     * (que dejaría el mismo bug abierto para cualquier otro camino de creación futuro).
     *
     * SCRUM-472 (REQ-402 RN1) — el movimiento de "Entrada" del Kardex se genera acá mismo, en el
     * único lugar donde una línea "Por ingresar" pasa a stock real. `product_warehouse_stock` ya
     * no se incrementa directo — `KardexService::registrar()` es ahora el único punto de
     * escritura de stock por bodega, para que el Kardex nunca pueda desincronizarse.
     *
     * SCRUM-429 (2026-08-14) — `catalog_products.stock_quantity` (el agregado) YA NO se
     * incrementa acá a mano: `KardexService::registrar()` ahora sincroniza ese mismo agregado
     * con el mismo delta (mismo fix que corrigió el bug real de Gerencia Test en Conteos
     * Generales, ver docblock de `KardexService`) — hacerlo ACÁ TAMBIÉN duplicaba el incremento.
     * El COALESCE que evitaba el bug de `stock_quantity` NULL (Pre-QA 2026-07-20, ver abajo)
     * sigue vigente, solo que ahora vive dentro de `registrar()`, no acá.
     */
    public function confirmLine(PurchaseOrderReceiptLine $line, ?int $userId = null): void
    {
        $orderId = $line->receipt?->purchase_order_id;

        $this->kardex->registrar(
            catalogProductId: $line->catalog_product_id,
            warehouseId: $line->warehouse_id,
            tipo: InventoryMovement::TIPO_ENTRADA,
            cantidad: $line->quantity,
            referenciaTexto: $orderId !== null ? "Orden #{$orderId} recibida" : 'Ingreso de mercancía confirmado',
            referenciaEntidadTipo: 'ORDEN_COMPRA',
            referenciaEntidadId: $orderId,
            realizadoPor: $userId,
        );
    }

    /**
     * REQ-142 Escenario 1 — confirma TODAS las líneas "Por ingresar" de TODOS los ingresos
     * registrados contra esta orden. Llamado al avanzar la orden a "Recibido"
     * (`PurchaseOrderController::advance()`) — mismo efecto de stock que confirmar manualmente
     * desde Inventario (`GoodsReceiptController::confirmPendingForProduct()`), sin pedirle nada al
     * usuario porque la bodega de cada línea ya se eligió al ingresar.
     *
     * @return int cantidad de líneas confirmadas
     *
     * Pre-QA 2026-08-06 (SCRUM-216/217, hallazgo CRÍTICO real, reproducido con 4-5 llamadas
     * concurrentes a `PurchaseOrderController::advance()` sobre la misma orden: el SELECT de
     * líneas pendientes vivía FUERA de la transacción y sin lock — 4 requests concurrentes leían
     * las mismas líneas "sin confirmar" antes de que cualquiera de ellas escribiera
     * `confirmed_at`, así que las 4 confirmaban la misma línea y cuadriplicaban el stock
     * (10 unidades reales → 40 en `stock_quantity`, 4 movimientos de Kardex "Entrada" idénticos
     * para un solo ingreso físico). Mismo patrón ya corregido antes en
     * `AdjustmentRequestController::approve()/reject()` (ver docblock de
     * `ComprasZonaLibreRequestController`): el SELECT + el chequeo de "todavía pendiente" ahora
     * viven DENTRO de la misma transacción, contra filas re-obtenidas con `lockForUpdate()` — un
     * segundo caller (incluso sin el fix de locking de `advance()` en la misma llamada, por
     * defensa en profundidad si este método se reusa desde otro lado en el futuro) queda
     * bloqueado hasta que el primero libera el lock, y en ese punto ya no encuentra líneas
     * pendientes.
     */
    public function confirmPendingForOrder(PurchaseOrder $order, ?int $userId = null): int
    {
        return DB::transaction(function () use ($order, $userId): int {
            $lines = PurchaseOrderReceiptLine::query()
                ->whereHas('receipt', fn ($q) => $q->where('purchase_order_id', $order->id))
                ->whereNull('confirmed_at')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $line->update(['confirmed_at' => now()]);
                $this->confirmLine($line, $userId);
            }

            return $lines->count();
        });
    }
}
