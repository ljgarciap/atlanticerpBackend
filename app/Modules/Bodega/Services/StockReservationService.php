<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-322, "back-allocation") — reserva blanda (`OrderItem.
 * qty_reserved` + `warehouse_id`) de stock físico entre pedidos que compiten por el mismo
 * producto mientras están "en vuelo" (picking/packing/por despachar). NO mueve
 * `ProductWarehouseStock.quantity` — eso sigue siendo exclusivo de `KardexService::registrar()`
 * en el momento del movimiento físico real (Despachado, ver `DeliveryService::dispatch()`).
 *
 * RN1 (REQ-322) — prioridad por `committed_delivery_date` ascendente (más próxima primero).
 * RN2 — desempate por `created_at` ascendente (el pedido más antiguo).
 *
 * "Back-allocation" real (no solo "primero en pedir, primero servido"): cada vez que un pedido
 * entra a competir por un producto (o sale de competir), se RECALCULA la asignación completa de
 * TODOS los pedidos que compiten por ese producto en ese momento, en orden de prioridad — un
 * pedido urgente que empieza picking después de uno menos urgente puede "recuperar" reserva que
 * ese pedido menos urgente ya tenía.
 *
 * Senior Review 2026-07-22 (Blocker 2, fix de diseño aprobado por el Arquitecto) — el cálculo es
 * CONSCIENTE DE BODEGA, no solo un agregado global: el reparto de la cadena de prioridad corre
 * "bin por bin" contra `ProductWarehouseStock` bodega por bodega (mismo lock que ya existía sobre
 * esas filas), así que dos pedidos consecutivos que en conjunto exceden el total de UNA bodega ya
 * no pueden terminar los dos apuntando a la misma bodega físicamente insuficiente mientras otra
 * queda con remanente sin usar. `PickingService::completePicking()` YA NO resuelve `warehouse_id`
 * de forma independiente — lee directamente el valor que este service ya calculó y bloqueó acá.
 *
 * Límite de diseño conocido y reportado (no se fuerza una solución rara — ver reporte del batch):
 * un solo `OrderItem` sigue apuntando a UNA sola bodega (`warehouse_id` es un FK simple, no una
 * tabla de reparto). El algoritmo usa asignación "best-fit" (la bodega con más remanente que
 * alcance a cubrir la necesidad) — si la necesidad de un ítem no cabe en NINGUNA bodega individual
 * aunque sí quepa en la suma de 2+ bodegas, la reserva queda acotada a lo que da la mejor bodega
 * individual (no se reparte una misma línea entre bodegas). El picker igual puede recolectar
 * físicamente de más de una bodega (la Hoja de Picking no se lo impide) — ese remanente sin
 * reservar simplemente no queda protegido contra otro pedido competidor, y si el picking termina
 * incompleto ya cae en el flujo de faltante existente (REQ-316/317), que es el mecanismo real de
 * negocio para ese caso — partir una línea entre bodegas de forma explícita requeriría una tabla
 * de reparto nueva (`order_items` no la tiene hoy), cambio de esquema fuera de alcance de esta
 * extensión.
 */
final class StockReservationService
{
    /** @var list<string> Etapas en las que el pedido todavía retiene stock físico sin haber salido de la bodega. */
    private const COMPETING_STAGES = [Order::STAGE_EN_PICKING, Order::STAGE_PACKING, Order::STAGE_POR_DESPACHAR];

    /**
     * Etapas en las que el picking de ese ítem ya es un HECHO físico consumado (no un plan) — su
     * `warehouse_id` queda CONGELADO (nunca se le reasigna una bodega distinta a la que ya se usó
     * para su picking), y su `qty_picked` se descuenta del remanente disponible de esa bodega
     * antes de repartir nada entre los ítems todavía "en plan" (fase 2, ver `reallocateProduct()`).
     */
    private const FROZEN_STAGES = [Order::STAGE_PACKING, Order::STAGE_POR_DESPACHAR];

    /** Punto de entrada al iniciar picking (REQ-314) o al reingresar a en_picking desde una revisión resuelta (REQ-318). */
    public function reserveForOrder(Order $order): void
    {
        $order->loadMissing('items');

        /** @var list<int> $productIds */
        $productIds = $order->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();

        $this->reallocateForProducts($productIds);
    }

    /** @param list<int> $productIds */
    public function reallocateForProducts(array $productIds): void
    {
        foreach (array_unique($productIds) as $productId) {
            $this->reallocateProduct((int) $productId);
        }
    }

    private function reallocateProduct(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            // Postgres no permite FOR UPDATE combinado con una función de agregación (sum()) en
            // el mismo SELECT — se bloquean las filas primero y se arma el mapa de remanente en PHP.
            $stockRows = ProductWarehouseStock::where('catalog_product_id', $productId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var array<int, int> $remainingByWarehouse bodega_id => remanente disponible para repartir. */
            $remainingByWarehouse = [];
            foreach ($stockRows as $row) {
                $remainingByWarehouse[$row->warehouse_id] = $row->quantity;
            }

            /** @var \Illuminate\Support\Collection<int, OrderItem> $items */
            $items = OrderItem::query()
                ->where('catalog_product_id', $productId)
                ->whereHas('order', fn (Builder $q) => $q->whereIn('stage', self::COMPETING_STAGES))
                ->with('order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $items = $items->sort(function (OrderItem $a, OrderItem $b): int {
                $orderA = $a->order;
                $orderB = $b->order;

                $dueA = $orderA->committed_delivery_date;
                $dueB = $orderB->committed_delivery_date;
                $dateA = $dueA !== null ? $dueA->timestamp : PHP_INT_MAX;
                $dateB = $dueB !== null ? $dueB->timestamp : PHP_INT_MAX;
                if ($dateA !== $dateB) {
                    return $dateA <=> $dateB;
                }

                $createdA = $orderA->created_at->timestamp;
                $createdB = $orderB->created_at->timestamp;
                if ($createdA !== $createdB) {
                    return $createdA <=> $createdB;
                }

                return $orderA->id <=> $orderB->id;
            })->values();

            // Fase 1 — CONGELAR: ítems cuyo picking ya es un hecho físico (Packing/Por despachar).
            // Su warehouse_id NO se toca; su qty_picked ya se descuenta del remanente de ESA
            // bodega antes de repartir nada entre los ítems todavía "en plan" (fase 2).
            $mutableItems = [];
            foreach ($items as $item) {
                $itemOrder = $item->order;
                $isFrozen  = in_array($itemOrder->stage, self::FROZEN_STAGES, true);

                if (! $isFrozen) {
                    $mutableItems[] = $item;
                    continue;
                }

                $frozenWarehouseId = $item->warehouse_id;
                $committed         = max(0, $item->qty_picked);

                if ($frozenWarehouseId !== null && $committed > 0) {
                    $remainingByWarehouse[$frozenWarehouseId] = ($remainingByWarehouse[$frozenWarehouseId] ?? 0) - $committed;
                }

                $frozenQtyReserved = $frozenWarehouseId !== null ? $committed : 0;
                if ($frozenQtyReserved !== $item->qty_reserved) {
                    $item->update(['qty_reserved' => $frozenQtyReserved]);
                }
            }

            // Fase 2 — REPARTIR: ítems todavía en plan (En picking, sin picking físico
            // consumado), en orden de prioridad, contra el remanente que dejó la fase 1.
            $remaining = $remainingByWarehouse;
            foreach ($mutableItems as $item) {
                $need = $item->pendingQty();

                [$warehouseId, $grant] = $this->bestFitAllocation($remaining, $need);

                if ($grant > 0 && $warehouseId !== null) {
                    $remaining[$warehouseId] -= $grant;
                }

                $resolvedWarehouseId = $grant > 0 ? $warehouseId : null;

                $updates = [];
                if ($grant !== $item->qty_reserved) {
                    $updates['qty_reserved'] = $grant;
                }
                if ($resolvedWarehouseId !== $item->warehouse_id) {
                    $updates['warehouse_id'] = $resolvedWarehouseId;
                }
                if ($updates !== []) {
                    $item->update($updates);
                }
            }
        });
    }

    /**
     * Best-fit: la bodega con MÁS remanente que pueda cubrir (total o parcialmente) la necesidad.
     *
     * @param array<int, int> $remainingByWarehouse
     * @return array{0: int|null, 1: int}
     */
    private function bestFitAllocation(array $remainingByWarehouse, int $need): array
    {
        if ($need <= 0) {
            return [null, 0];
        }

        $bestWarehouseId = null;
        $bestRemaining   = 0;
        foreach ($remainingByWarehouse as $warehouseId => $remaining) {
            if ($remaining > $bestRemaining) {
                $bestRemaining   = $remaining;
                $bestWarehouseId = $warehouseId;
            }
        }

        if ($bestWarehouseId === null) {
            return [null, 0];
        }

        return [$bestWarehouseId, min($need, $bestRemaining)];
    }
}
