<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\OrderItemAllocation;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
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
 *
 * SCRUM-1196 (reglas de Lider Bodega Liderbodega, Líder de Bodega, 22-10-2026) — el «bin por bin» ya NO usa todas las bodegas operativas ni la de más remanente
 * (`bestFitAllocation()`, eliminado: tomaba Reclamos, Merma o un Exhibición y los dejaba en negativo). Solo cuentan las bodegas `permite_venta`; la reserva
 * AUTOMÁTICA sale únicamente de la bodega `despacho_por_defecto` (Bodega Central), nunca más que su saldo (jamás negativo); lo que no alcance queda sin reservar
 * y al completar el picking cae en el flujo de faltante (REQ-316/317, «pendiente de compra»). Una bodega de venta distinta (1 pieza de Sala de exhibición 1) solo
 * entra si Bodega la ESCOGE en el picking: esa asignación `manual` se conserva y se respeta como parte del saldo. El reparto por bodega vive en
 * `order_item_allocations`; `order_items.warehouse_id/qty_reserved` quedan como bodega principal y total.
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

    /** @var array{saleIds: list<int>, defaultId: int|null, names: array<int, string>}|null */
    private ?array $warehouseCtx = null;

    /**
     * SCRUM-1196 — bodegas de venta, bodega por defecto y nombres, cargados UNA vez por operación (antes cada línea y cada producto repetía estas consultas:
     * 60 líneas ≈ 500 ms). Se descarta al empezar cada punto de entrada para no arrastrar reglas viejas entre operaciones (procesos largos como Horizon).
     *
     * @return array{saleIds: list<int>, defaultId: int|null, names: array<int, string>}
     */
    public function warehouseContext(): array
    {
        if ($this->warehouseCtx === null) {
            $names = [];
            foreach (Warehouse::query()->get(['id', 'name']) as $w) {
                $names[(int) $w->id] = (string) $w->name;
            }

            $this->warehouseCtx = [
                'saleIds'   => array_values(array_map('intval', Warehouse::paraVenta()->pluck('id')->all())),
                'defaultId' => Warehouse::despachoPorDefecto()?->id,
                'names'     => $names,
            ];
        }

        return $this->warehouseCtx;
    }

    public function forgetWarehouseContext(): void
    {
        $this->warehouseCtx = null;
    }

    /** Punto de entrada al iniciar picking (REQ-314) o al reingresar a en_picking desde una revisión resuelta (REQ-318). */
    public function reserveForOrder(Order $order): void
    {
        $this->forgetWarehouseContext();
        $order->loadMissing('items');

        /** @var list<int> $productIds */
        $productIds = $order->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();

        $this->reallocateForProducts($productIds);
    }

    /** @param list<int> $productIds */
    public function reallocateForProducts(array $productIds): void
    {
        $this->forgetWarehouseContext();
        foreach (array_unique($productIds) as $productId) {
            $this->reallocateProduct((int) $productId);
        }
    }

    private function reallocateProduct(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            // Postgres no permite FOR UPDATE combinado con una función de agregación (sum()) en
            // el mismo SELECT — se bloquean las filas primero y se arma el mapa de remanente en PHP.
            // SCRUM-990: solo bodegas OPERATIVAS. Una fila residual de una bodega de solo consulta no puede recibir reservas: el kardex del
            // picking/despacho posterior fallaría y el pedido quedaría bloqueado.
            $stockRows = ProductWarehouseStock::where('catalog_product_id', $productId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // SCRUM-1196 — solo las bodegas que pueden surtir ventas; el remanente nunca es negativo (un saldo ya negativo no se puede reservar).
            $ctx = $this->warehouseContext();
            $saleWarehouseIds   = $ctx['saleIds'];
            $defaultWarehouseId = $ctx['defaultId'];

            /** @var array<int, int> $remainingByWarehouse bodega_id => remanente disponible para repartir (solo bodegas de venta, ≥ 0). */
            $remainingByWarehouse = [];
            foreach ($stockRows as $row) {
                if (in_array((int) $row->warehouse_id, $saleWarehouseIds, true)) {
                    $remainingByWarehouse[(int) $row->warehouse_id] = max(0, (int) $row->quantity);
                }
            }

            /** @var \Illuminate\Support\Collection<int, OrderItem> $items */
            $items = OrderItem::query()
                ->where('catalog_product_id', $productId)
                ->whereHas('order', fn (Builder $q) => $q->whereIn('stage', self::COMPETING_STAGES))
                ->with(['order', 'allocations'])
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

                // Por bodega: con asignaciones (SCRUM-1196) lo alistado de cada una; sin ellas (pedidos previos) la bodega única de la línea.
                $committedByWarehouse = $item->allocations->isNotEmpty()
                    ? $item->allocations->mapWithKeys(fn (OrderItemAllocation $al) => [(int) $al->warehouse_id => max(0, (int) $al->qty_picked)])->all()
                    : ($item->warehouse_id !== null ? [(int) $item->warehouse_id => max(0, $item->qty_picked)] : []);

                $committed = 0;
                foreach ($committedByWarehouse as $warehouseId => $qty) {
                    $committed += $qty;
                    if ($qty > 0 && isset($remainingByWarehouse[$warehouseId])) {
                        $remainingByWarehouse[$warehouseId] = max(0, $remainingByWarehouse[$warehouseId] - $qty);
                    }
                }

                foreach ($item->allocations as $allocation) {
                    if ($allocation->qty_reserved !== $allocation->qty_picked) {
                        $allocation->update(['qty_reserved' => $allocation->qty_picked]);
                    }
                }

                $frozenQtyReserved = $item->warehouse_id !== null || $item->allocations->isNotEmpty() ? $committed : 0;
                if ($frozenQtyReserved !== $item->qty_reserved) {
                    $item->update(['qty_reserved' => $frozenQtyReserved]);
                }
            }

            // Fase 1b (SCRUM-1196, Pre-QA ronda 4 N4) — lo que un pedido EN PICKING ya alistó es un hecho físico aunque el pedido todavía no esté congelado:
            // se aparta del saldo antes de repartir, para que un pedido de mayor prioridad no se lleve unidades ya alistadas por otro (la reserva total
            // superaba el stock tras cambiar la bodega por defecto bajo contención). Cada ítem recupera lo SUYO justo antes de que le toque repartir.
            /** @var array<int, array<int, int>> $held item_id => [bodega_id => unidades apartadas] */
            $held = [];
            foreach ($mutableItems as $item) {
                foreach ($item->allocations as $allocation) {
                    $warehouseId = (int) $allocation->warehouse_id;
                    $picked = max(0, (int) $allocation->qty_picked);
                    if ($picked > 0 && isset($remainingByWarehouse[$warehouseId])) {
                        $hold = min($remainingByWarehouse[$warehouseId], $picked);
                        $remainingByWarehouse[$warehouseId] -= $hold;
                        $held[$item->id][$warehouseId] = ($held[$item->id][$warehouseId] ?? 0) + $hold;
                    }
                }
            }

            // Fase 2 — REPARTIR: ítems todavía en plan (En picking, sin picking físico consumado), en orden de prioridad, contra el remanente que dejó la fase 1.
            foreach ($mutableItems as $item) {
                foreach ($held[$item->id] ?? [] as $warehouseId => $qty) {
                    $remainingByWarehouse[$warehouseId] += $qty;
                }
                $this->allocateMutableItem($item, $remainingByWarehouse, $defaultWarehouseId);
            }
        });
    }

    /**
     * SCRUM-1196 — reparte UN ítem todavía en plan: primero respeta lo que Bodega escogió a mano en el picking (acotado al saldo que queda), y el resto
     * lo cubre con la bodega de despacho por defecto, sin pasar de su saldo. Lo que no se cubre queda sin reservar (faltante).
     *
     * @param array<int, int> $remaining remanente por bodega de venta; se descuenta lo reservado acá.
     */
    private function allocateMutableItem(OrderItem $item, array &$remaining, ?int $defaultWarehouseId): void
    {
        $need = $item->pendingQty();

        // Una fila MANUAL en la bodega que acaba de pasar a ser la de por defecto (el picker la había escogido a mano) se fusiona con la automática de esa
        // bodega: la tabla permite UNA fila por (línea, bodega), así que crear otra daba un 500 y dejaba a todos los pedidos del producto sin poder alistar.
        if ($defaultWarehouseId !== null) {
            $manualInDefault = $item->allocations->first(fn (OrderItemAllocation $al): bool => $al->manual && (int) $al->warehouse_id === $defaultWarehouseId);
            if ($manualInDefault !== null) {
                $manualInDefault->update(['manual' => false]);
            }
        }

        $manualGranted = 0;
        $firstManualWarehouse = null;
        foreach ($item->allocations->where('manual', true) as $allocation) {
            $warehouseId = (int) $allocation->warehouse_id;
            // Lo que la fila manual quiere es al menos lo ya alistado: si su bodega dejó de ser de venta (reserva 0) y vuelve a serlo, recupera su reserva en vez de
            // quedarse en 0 con piezas alistadas (Pre-QA ronda 5, N5).
            $wanted = max(0, (int) $allocation->qty_reserved, (int) $allocation->qty_picked);
            $grant  = min($wanted, max(0, $remaining[$warehouseId] ?? 0), max(0, $need - $manualGranted));
            if (isset($remaining[$warehouseId])) {
                $remaining[$warehouseId] -= $grant;
            }
            $manualGranted += $grant;
            if ($grant > 0) {
                $firstManualWarehouse ??= $warehouseId;
            }

            if ($grant <= 0 && $allocation->qty_picked <= 0) {
                $allocation->delete();
            } elseif ($grant !== $allocation->qty_reserved) {
                $allocation->update(['qty_reserved' => $grant]);
            }
        }

        // Filas AUTOMÁTICAS de una bodega que ya no es la de por defecto (un superadmin cambió la regla con el pedido en picking): se liberan. Lo que ya se
        // alistó allí se respeta (es un hecho físico) y cuenta contra su saldo y contra lo que falta; el resto de esa reserva desaparece. Sin esto la
        // reserva quedaba duplicada (bodega vieja + bodega nueva) y los demás pedidos veían ese saldo como comprometido.
        $staleKept = 0;
        foreach ($item->allocations->where('manual', false) as $stale) {
            if ($defaultWarehouseId !== null && (int) $stale->warehouse_id === $defaultWarehouseId) {
                continue;
            }
            $keep = max(0, (int) $stale->qty_picked);
            if (isset($remaining[(int) $stale->warehouse_id])) {
                $remaining[(int) $stale->warehouse_id] = max(0, $remaining[(int) $stale->warehouse_id] - $keep);
            }
            $staleKept += $keep;
            if ($keep === 0) {
                $stale->delete();
            } elseif ($stale->qty_reserved !== $keep) {
                $stale->update(['qty_reserved' => $keep]);
            }
        }

        $autoGrant = 0;
        if ($defaultWarehouseId !== null) {
            $autoNeed  = max(0, $need - $manualGranted - $staleKept);
            $auto = $item->allocations->first(fn (OrderItemAllocation $al): bool => ! $al->manual && (int) $al->warehouse_id === $defaultWarehouseId);
            $autoGrant = min($autoNeed, max(0, $remaining[$defaultWarehouseId] ?? 0));
            // Lo ya alistado en esa bodega no puede quedar por encima de lo reservado (un alistado manual que pasó a ser automático): se reserva al menos eso.
            if ($auto !== null) {
                $autoGrant = max($autoGrant, min(max(0, (int) $auto->qty_picked), $autoNeed));
            }
            if (isset($remaining[$defaultWarehouseId])) {
                $remaining[$defaultWarehouseId] = max(0, $remaining[$defaultWarehouseId] - $autoGrant);
            }

            if ($auto !== null) {
                if ($autoGrant <= 0 && $auto->qty_picked <= 0) {
                    $auto->delete();
                } elseif ($autoGrant !== $auto->qty_reserved) {
                    $auto->update(['qty_reserved' => $autoGrant]);
                }
            } elseif ($autoGrant > 0) {
                OrderItemAllocation::create([
                    'order_item_id' => $item->id, 'warehouse_id' => $defaultWarehouseId, 'qty_reserved' => $autoGrant, 'qty_picked' => 0, 'manual' => false,
                ]);
            }
        }

        $total = $autoGrant + $manualGranted + $staleKept;
        $mainWarehouseId = $autoGrant > 0 ? $defaultWarehouseId : $firstManualWarehouse;

        $updates = [];
        if ($total !== $item->qty_reserved) {
            $updates['qty_reserved'] = $total;
        }
        if ($mainWarehouseId !== $item->warehouse_id) {
            $updates['warehouse_id'] = $mainWarehouseId;
        }
        if ($updates !== []) {
            $item->update($updates);
        }

        $item->unsetRelation('allocations');
    }

    /**
     * SCRUM-1196 — saldo de UNA bodega de venta que sigue libre para un pedido: el stock actual menos lo que otros pedidos en vuelo ya tienen reservado
     * allí (o alistado, si ya están en Packing/Por despachar). Es el tope que valida el picking cuando Bodega escoge una bodega distinta a la de por defecto.
     */
    public function availableAtWarehouse(int $productId, int $warehouseId, int $excludeOrderItemId, bool $lock = false): int
    {
        // `$lock` (solo dentro de una transacción, al validar un picking): bloquea la fila de stock para que dos pickers que validan a la vez
        // la última pieza de una bodega se serialicen — el segundo ve el saldo ya reservado por el primero.
        $stockQuery = ProductWarehouseStock::where('catalog_product_id', $productId)->where('warehouse_id', $warehouseId);
        if ($lock) {
            $stockQuery->lockForUpdate();
        }
        $stock = (int) ($stockQuery->value('quantity') ?? 0);

        $items = OrderItem::query()
            ->where('catalog_product_id', $productId)
            ->where('id', '!=', $excludeOrderItemId)
            ->whereHas('order', fn (Builder $q) => $q->whereIn('stage', self::COMPETING_STAGES))
            ->with(['order', 'allocations'])
            ->get();

        $taken = 0;
        foreach ($items as $other) {
            $frozen = in_array($other->order->stage, self::FROZEN_STAGES, true);
            if ($other->allocations->isNotEmpty()) {
                foreach ($other->allocations->where('warehouse_id', $warehouseId) as $allocation) {
                    $taken += $frozen ? $allocation->qty_picked : $allocation->qty_reserved;
                }
            } elseif ($other->warehouse_id === $warehouseId) {
                $taken += $frozen ? $other->qty_picked : $other->qty_reserved;
            }
        }

        return max(0, $stock - $taken);
    }
}
