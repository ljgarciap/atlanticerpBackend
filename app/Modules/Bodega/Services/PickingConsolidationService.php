<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Collection;

/**
 * SCRUM-391 (REQ-321) — "Consolidado del día por picker": junta, PARA UN SOLO picker, todo lo
 * que tiene que recolectar de TODOS sus pedidos activos en una sola lista por producto, en vez de
 * hacer picking pedido por pedido cuando dos pedidos piden el mismo artículo.
 *
 * RN1 — solo cuentan pedidos en `Order::STAGE_EN_PICKING` (el picker ya empezó a trabajar ese
 * pedido, ver `PickingService::startPicking()`); `picking_pendiente` (todavía en cola, sin picker
 * "trabajando" activamente) queda afuera a propósito — mismo criterio que separa esas dos etapas
 * en el tablero Kanban (REQ-311).
 * RN2 — solo lectura: esto es una vista de consulta, ninguna mutación de `OrderItem`/`Order`
 * vive acá (la única forma de tocar cantidades sigue siendo `PickingService::updatePickingSheet()`
 * /`completePicking()`, pedido por pedido).
 *
 * Consolidación: la clave es `catalog_product_id` — un ítem "personalizado" (`is_custom`, sin
 * producto de Catálogo, `catalog_product_id = null`) NUNCA se consolida con otro ítem personalizado
 * de otro pedido, aunque comparta la misma referencia/descripción textual, porque no hay un id
 * estable para agruparlos (ver docblock de `OrderItem`) — cada uno queda en su propia línea.
 *
 * `location` (corrección 2026-08-11, Daniela Amaya, SCRUM-391) — columna obligatoria agregada al
 * consolidado: usa `found_location` (ubicación real confirmada por una Revisión de Inventario,
 * REQ-318) cuando existe, si no cae a `location_snapshot` (la original) — mismo criterio de
 * prioridad que la Hoja de Picking individual. Se fija una sola vez, al crear la fila consolidada
 * (mismo patrón que `reference`/`description`), asumiendo que el mismo producto vive en la misma
 * ubicación entre los pedidos que se están consolidando para este picker.
 */
final class PickingConsolidationService
{
    /**
     * Shape de cada elemento de `items`: `{catalog_product_id: int|null, reference: string|null,
     * description: string|null, location: string|null, qty_requested: int, orders: list<array{order_id: int,
     * order_number: string, qty_requested: int}>}` — no se declara como array shape estricto en
     * el docblock de retorno porque se arma incrementalmente dentro del loop (PHPStan no puede
     * seguir el shape exacto a través de las mutaciones); el contrato real lo fijan los tests.
     *
     * @return array{picker_id: int, orders_count: int, items: list<array<string, mixed>>}
     */
    public function consolidatedForPicker(int $pickerId): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = Order::query()
            ->where('stage', Order::STAGE_EN_PICKING)
            ->where('picker_id', $pickerId)
            ->with('items.catalogProduct')
            ->orderBy('order_number')
            ->get();

        /** @var array<string, array<string, mixed>> $consolidated keyed by "product:<id>" or "item:<order_item_id>" */
        $consolidated = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $key = $this->consolidationKey($item);

                if (! isset($consolidated[$key])) {
                    /** @var CatalogProduct|null $product */
                    $product = $item->catalogProduct;

                    $consolidated[$key] = [
                        'catalog_product_id' => $item->catalog_product_id,
                        'reference'          => $product !== null ? $product->reference : $item->reference_snapshot,
                        'description'        => $product !== null ? $product->name : $item->description_snapshot,
                        'location'           => $item->found_location ?? $item->location_snapshot,
                        'qty_requested'      => 0,
                        'orders'             => [],
                    ];
                }

                $consolidated[$key]['qty_requested'] += $item->qty_requested;
                $consolidated[$key]['orders'][] = [
                    'order_id'      => $order->id,
                    'order_number'  => $order->order_number,
                    'qty_requested' => $item->qty_requested,
                ];
            }
        }

        return [
            'picker_id'    => $pickerId,
            'orders_count' => $orders->count(),
            'items'        => array_values($consolidated),
        ];
    }

    private function consolidationKey(OrderItem $item): string
    {
        return $item->catalog_product_id !== null
            ? "product:{$item->catalog_product_id}"
            : "item:{$item->id}";
    }
}
