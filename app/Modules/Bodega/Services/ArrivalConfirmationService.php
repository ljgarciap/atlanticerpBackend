<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Events\ArrivalConfirmedByWarehouse;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Bloque B2 (REQ-357/427) — "Confirmar llegada física", Paso 1 de un flujo de 2 pasos y
 * 2 responsables distintos:
 *   Paso 1 (Bodega, ESTE service) — avisa que la mercancía llegó FÍSICAMENTE, con la cantidad
 *   REAL contada (puede ser menor a lo pedido — "llegó incompleto"). Es solo un aviso/registro,
 *   NO factura al inventario.
 *   Paso 2 (Compras, ya existe, NO SE TOCA) — `GoodsReceiptController::confirm()`/
 *   `confirmPendingForProduct()` es quien factura de verdad al inventario, sigue siendo
 *   responsabilidad exclusiva de Compras (RN3 de REQ-357).
 *
 * Opera sobre las MISMAS líneas `purchase_order_receipt_lines` que ya usa Compras para "Por
 * ingresar" (`confirmed_at` NULL) — ver docblock de la migración para el porqué de reusar la
 * tabla en vez de crear una nueva. Granularidad por PRODUCTO (no por línea individual de un
 * ingreso interno de Compras, que Bodega no conoce como entidad): mismo criterio que
 * `GoodsReceiptController::confirmPendingForProduct()`, "confirmar TODO lo pendiente de este
 * producto de una vez".
 */
final class ArrivalConfirmationService
{
    /**
     * REQ-357 Escenario 1 / RN2 — reparte `$cantidadEntregada` entre las líneas "Por ingresar"
     * (`confirmed_at` NULL) que Bodega todavía no reportó (`bodega_confirmed_at` NULL), en orden
     * FIFO (la línea más antigua primero), hasta el `quantity` propio de cada línea — así
     * `cantidad_entregada <= cantidad_pedida` queda garantizado POR LÍNEA de forma estructural
     * (nunca se le asigna a una línea más de lo que esa línea pidió), no solo con una validación
     * de límite al final.
     *
     * Si `$cantidadEntregada` es menor al total pendiente, las líneas que no alcanzan a recibir
     * nada (o que reciben menos que su `quantity`) se marcan igual como reportadas por Bodega con
     * el monto real que les tocó — el faltante queda registrado en
     * `bodega_confirmed_quantity < quantity` como historial de "llegó incompleto"; si una línea no
     * recibe NADA de este reparto, se deja sin tocar (sigue "pendiente de reportar" para la
     * próxima llegada real de esa línea).
     *
     * @return array{status: int, message?: string, confirmed_lines?: list<int>, confirmed_quantity?: int}
     */
    public function confirmArrival(int $productId, int $cantidadEntregada, int $userId): array
    {
        return DB::transaction(function () use ($productId, $cantidadEntregada, $userId): array {
            /** @var Collection<int, PurchaseOrderReceiptLine> $lines */
            $lines = PurchaseOrderReceiptLine::query()
                ->where('catalog_product_id', $productId)
                ->whereNull('confirmed_at')
                ->whereNull('bodega_confirmed_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                return ['status' => 422, 'message' => 'Este producto no tiene unidades "Por ingresar" pendientes de reportar.'];
            }

            $totalPedido = (int) $lines->sum('quantity');
            if ($cantidadEntregada > $totalPedido) {
                return ['status' => 422, 'message' => "La cantidad entregada no puede superar lo pedido pendiente ({$totalPedido} unidades)."];
            }

            $remaining = $cantidadEntregada;
            $confirmedLineIds = [];
            foreach ($lines as $line) {
                if ($remaining <= 0) {
                    break;
                }

                $grant = min($remaining, (int) $line->quantity);
                $remaining -= $grant;

                $line->update([
                    'bodega_confirmed_at'       => now(),
                    'bodega_confirmed_quantity' => $grant,
                    'bodega_confirmed_by'       => $userId,
                ]);
                $confirmedLineIds[] = $line->id;
            }

            event(new ArrivalConfirmedByWarehouse(
                catalogProductId: $productId,
                confirmedQuantity: $cantidadEntregada,
                confirmedBy: $userId,
                tenant: (string) Tenant::current()?->slug,
            ));

            return ['status' => 200, 'confirmed_lines' => $confirmedLineIds, 'confirmed_quantity' => $cantidadEntregada];
        });
    }

    /**
     * REQ-357 RN2 — estado del "Confirmar llegada física" para un producto, para que el frontend
     * decida entre mostrar el botón o la nota de espera:
     *   - `pending_bodega_action`: hay líneas "Por ingresar" que Bodega todavía no reportó (botón).
     *   - `awaiting_compras`: hay líneas que Bodega YA reportó pero Compras todavía no finalizó
     *     (nota "ya confirmaste, esperando a que Compras finalice").
     *   - `confirmed_quantity`/`confirmed_by_name` (rebote REQ-427, Daniela Amaya 2026-08-13) — la
     *     cantidad total ya reportada y el nombre de quién la reportó, para que tanto la nota de
     *     Bodega como el detalle de Compras muestren el dato real en vez de un genérico "esperando".
     *
     * @return array{pending_bodega_action: bool, awaiting_compras: bool, confirmed_quantity: int, confirmed_by_name: string|null}
     */
    public function pendingSummary(int $productId): array
    {
        return $this->bulkPendingSummary([$productId])[$productId]
            ?? ['pending_bodega_action' => false, 'awaiting_compras' => false, 'confirmed_quantity' => 0, 'confirmed_by_name' => null];
    }

    /**
     * Mismo cálculo que `pendingSummary()`, para todos los productos de una tabla paginada a la
     * vez (1 sola query) — evita 1 query por fila en `BodegaInventoryController::index()`.
     *
     * @param list<int> $productIds
     * @return array<int, array{pending_bodega_action: bool, awaiting_compras: bool, confirmed_quantity: int, confirmed_by_name: string|null}>
     */
    public function bulkPendingSummary(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var Collection<int, PurchaseOrderReceiptLine> $pendingLines */
        $pendingLines = PurchaseOrderReceiptLine::whereIn('catalog_product_id', $productIds)
            ->whereNull('confirmed_at')
            ->with('bodegaConfirmedByUser:id,first_name,last_name')
            ->get()
            ->groupBy('catalog_product_id');

        $result = [];
        foreach ($productIds as $productId) {
            /** @var Collection<int, PurchaseOrderReceiptLine> $lines */
            $lines = $pendingLines->get($productId, collect());

            /** @var Collection<int, PurchaseOrderReceiptLine> $confirmedLines */
            $confirmedLines = $lines->filter(fn (PurchaseOrderReceiptLine $l): bool => $l->isBodegaConfirmed());
            /** @var PurchaseOrderReceiptLine|null $latestConfirmed */
            $latestConfirmed = $confirmedLines->sortByDesc('bodega_confirmed_at')->first();
            $confirmedByUser = $latestConfirmed?->bodegaConfirmedByUser;

            $result[$productId] = [
                'pending_bodega_action' => $lines->contains(fn (PurchaseOrderReceiptLine $l): bool => $l->needsBodegaConfirmation()),
                'awaiting_compras'      => $confirmedLines->isNotEmpty(),
                'confirmed_quantity'    => (int) $confirmedLines->sum('bodega_confirmed_quantity'),
                'confirmed_by_name'     => $confirmedByUser !== null ? trim("{$confirmedByUser->first_name} {$confirmedByUser->last_name}") : null,
            ];
        }

        return $result;
    }
}
