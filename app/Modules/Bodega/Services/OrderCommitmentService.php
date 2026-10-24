<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Bloque B2 (REQ-353/423, Ver Inventario de Bodega) — "Por servir" de esta pantalla:
 * unidades comprometidas a PEDIDOS de Bodega todavía en curso. Esto es un concepto DISTINTO del
 * "por servir" que ya existe en `InventoryKpiService::porServirMap()` (reservas de Ventas & Diseño
 * capturadas en `PurchaseOrderLine.sales_project_id` en el momento de COMPRAR, ver docblock de ese
 * método) — mismo nombre de negocio, cálculo distinto, service separado a propósito para que nadie
 * los mezcle ni los reuse de forma cruzada.
 *
 * RN1 (REQ-353) — solo cuentan pedidos en las 5 etapas activas del flujo de Bodega (Asignado →
 * Por despachar, ver `Order::STAGE_ORDER`); Despachado/Entregado ya se descontaron del inventario
 * disponible, no son "compromiso" sino historia. Usa `OrderItem::pendingQty()`
 * (qty_requested - qty_delivered, mismo cálculo que ya usa `StockReservationService`) en vez de
 * `qty_requested` a secas, para que una familia con entrega parcial no siga contando como
 * comprometido lo que ya se entregó.
 */
final class OrderCommitmentService
{
    /** @var list<string> Etapas activas del flujo de Bodega — REQ-353 RN1. */
    public const ACTIVE_STAGES = [
        Order::STAGE_ASIGNADO,
        Order::STAGE_PICKING_PENDIENTE,
        Order::STAGE_EN_PICKING,
        Order::STAGE_PACKING,
        Order::STAGE_POR_DESPACHAR,
    ];

    /**
     * @param list<int> $productIds
     * @return array<int, int> catalog_product_id => unidades comprometidas
     */
    public function committedMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('order', fn ($q) => $q->whereIn('stage', self::ACTIVE_STAGES))
            ->get();

        $map = [];
        foreach ($items as $item) {
            $productId = (int) $item->catalog_product_id;
            $map[$productId] = ($map[$productId] ?? 0) + $item->pendingQty();
        }

        return $map;
    }

    /**
     * SCRUM-992 (H1) — mismo cálculo que `committedMap()` (suma de `max(0, qty_requested -
     * qty_delivered)` por producto, solo pedidos en etapa activa) como subconsulta agrupada, para
     * filtrar/contar sobre TODO el catálogo sin hidratar los `OrderItem` ni pasar decenas de miles de
     * IDs. Devuelve `catalog_product_id, total`. Cualquier cambio de regla se hace en los dos lados;
     * `CatalogControllerTest` (paridad del filtro `stock`) falla si divergen.
     */
    public function committedSub(): QueryBuilder
    {
        $orders = (new Order())->getTable();

        return (new OrderItem())->newQuery()->toBase()
            ->whereNotNull('catalog_product_id')
            ->whereIn('order_id', fn ($q) => $q->select('id')->from($orders)->whereIn('stage', self::ACTIVE_STAGES))
            ->selectRaw('catalog_product_id, SUM(GREATEST(0, qty_requested - qty_delivered)) AS total')
            ->groupBy('catalog_product_id');
    }

    /**
     * REQ-353 Escenario 1/2 — detalle por pedido para el drill-down de "Por servir": cliente,
     * proyecto, pedido y estado actual de cada compromiso. `[]` si no hay nada comprometido — el
     * caller (endpoint) lo traduce en "sin compromisos activos" en vez de abrir un modal vacío sin
     * explicación, nunca un error.
     *
     * @return list<array{order_id: int, order_number: string, customer_name: string|null, project_name: string|null, stage: string, quantity: int}>
     */
    public function committedDetail(int $productId): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()
            ->where('catalog_product_id', $productId)
            ->whereHas('order', fn ($q) => $q->whereIn('stage', self::ACTIVE_STAGES))
            ->with(['order.pipelineCard.salesProject'])
            ->get();

        /** @var Collection<int|string, Collection<int, OrderItem>> $byOrder */
        $byOrder = $items->groupBy('order_id');

        $rows = $byOrder->map(function (Collection $orderItems): array {
            /** @var OrderItem $first */
            $first = $orderItems->first();
            $order = $first->order;
            $quantity = (int) $orderItems->sum(fn (OrderItem $i) => $i->pendingQty());

            return [
                'order_id'      => $order->id,
                'order_number'  => $order->order_number,
                'customer_name' => $order->customer_name,
                'project_name'  => $order->pipelineCard?->salesProject?->name,
                'stage'         => $order->stage,
                'quantity'      => $quantity,
            ];
        })->filter(fn (array $row): bool => $row['quantity'] > 0)->values();

        return array_values($rows->all());
    }
}
