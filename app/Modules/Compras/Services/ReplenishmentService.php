<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\Quote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * SCRUM-1184 — «Pendientes de reposición»: consolida lo que falta de los pedidos que hoy están «Sin stock» y lo agrupa por proveedor para que
 * Compras arme las órdenes de compra de varios productos de una sola vez. Solo lee: no crea ni modifica nada propio.
 *
 * La fuente es la misma condición que usa `ReleaseOrdersAwaitingStock` (pedido de faltante asignado, con ítems `sin_stock` aún sin encontrar),
 * de modo que la pantalla y la liberación automática nunca discrepan: al recibirse la orden de compra y liberarse el pedido, la fila desaparece sola.
 * La consulta se acota a esos pedidos y a los ids de sus productos — nunca recorre el catálogo completo.
 */
final class ReplenishmentService
{
    public function __construct(private readonly InventoryKpiService $inventoryKpis) {}

    /**
     * @return array{
     *   groups: list<array{provider: array{id: int, name: string}|null, products: list<array<string, mixed>>}>,
     *   custom_items_count: int,
     *   total_products: int
     * }
     */
    public function summary(): array
    {
        $orders = Order::query()
            ->where('order_type', Order::ORDER_TYPE_FALTANTE)
            ->where('stage', Order::STAGE_ASIGNADO)
            ->with([
                'items' => fn ($q) => $q->where('shortage_reason', OrderItem::SHORTAGE_SIN_STOCK)->where('found_at_location', false),
                'pipelineCard.salesProject',
            ])
            ->orderBy('updated_at')
            ->get();

        // Folio de la cotización de cada pedido (la que el vendedor reconoce, y por la que busca «Pedidos de venta»): misma regla que
        // `Order::linkedQuote()` (la última con folio del proyecto), resuelta en lote para no hacer una consulta por pedido.
        $projectIds = $orders->map(fn (Order $o) => $o->pipelineCard?->sales_project_id)->filter()->unique()->values()->all();
        /** @var array<int, string> $folioByProject */
        $folioByProject = [];
        if ($projectIds !== []) {
            foreach (Quote::query()->whereIn('sales_project_id', $projectIds)->whereNotNull('folio')->orderByDesc('id')->get(['id', 'sales_project_id', 'folio']) as $quote) {
                $folioByProject[(int) $quote->sales_project_id] ??= (string) $quote->folio;
            }
        }

        /** @var array<int, array{faltante: int, orders: array<int, array<string, mixed>>}> $byProduct */
        $byProduct = [];
        $customItems = 0;

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $qty = $item->pendingQty();
                if ($qty <= 0) {
                    continue;
                }
                if ($item->catalog_product_id === null) {
                    $customItems++;

                    continue;
                }

                $productId = (int) $item->catalog_product_id;
                $byProduct[$productId] ??= ['faltante' => 0, 'orders' => []];
                $byProduct[$productId]['faltante'] += $qty;

                $row = $byProduct[$productId]['orders'][$order->id] ?? [
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'project'      => $order->pipelineCard?->salesProject?->name,
                    'quote_folio'  => $folioByProject[(int) $order->pipelineCard?->sales_project_id] ?? null,
                    'since'        => $order->updated_at->toIso8601String(),
                    'qty'          => 0,
                ];
                $row['qty'] += $qty;
                $byProduct[$productId]['orders'][$order->id] = $row;
            }
        }

        $productIds = array_keys($byProduct);
        /** @var Collection<int, CatalogProduct> $products */
        $products = $productIds === []
            ? new Collection()
            : CatalogProduct::query()->whereIn('id', $productIds)->with(['provider', 'providers'])->get()->keyBy('id');
        $enCamino = $this->inventoryKpis->enCaminoMap($productIds);

        /** @var array<int|string, array{provider: array{id: int, name: string}|null, products: list<array<string, mixed>>}> $groups */
        $groups = [];
        foreach ($byProduct as $productId => $data) {
            $product = $products->get($productId);
            if ($product === null) {
                continue;
            }

            $providerKey = $product->provider_id ?? 'none';
            $groups[$providerKey] ??= [
                'provider' => $product->provider !== null ? ['id' => (int) $product->provider->id, 'name' => (string) $product->provider->name] : null,
                'products' => [],
            ];

            $alternatives = $product->providers
                ->map(fn ($p): array => ['id' => (int) $p->id, 'name' => (string) $p->name])
                ->values()->all();
            // El principal siempre es elegible aunque la pivote no lo repita (productos creados por otros caminos).
            if ($product->provider !== null && ! in_array((int) $product->provider->id, array_column($alternatives, 'id'), true)) {
                array_unshift($alternatives, ['id' => (int) $product->provider->id, 'name' => (string) $product->provider->name]);
            }

            $orderRows = array_values($data['orders']);
            $groups[$providerKey]['products'][] = [
                'catalog_product_id' => $productId,
                'reference'          => (string) $product->reference,
                'name'               => (string) ($product->name ?? $product->description ?? ''),
                'faltante'           => $data['faltante'],
                'stock'              => (int) ($product->stock_quantity ?? 0),
                // Mismo costo unitario que muestra Inventario (costo + importación + flete + manejo + otros), para precargar la línea de la orden.
                'unit_cost'          => $product->costTotal(),
                'en_camino'          => (int) ($enCamino[$productId] ?? 0),
                'since'              => $orderRows[0]['since'] ?? null,
                'orders'             => $orderRows,
                'providers'          => $alternatives,
            ];
        }

        $list = array_values($groups);
        foreach ($list as &$group) {
            usort($group['products'], fn (array $a, array $b): int => strcmp((string) $a['since'], (string) $b['since']));
        }
        unset($group);
        // Por nombre de proveedor; «Sin proveedor» siempre al final.
        usort($list, function (array $a, array $b): int {
            if ($a['provider'] === null || $b['provider'] === null) {
                return ($a['provider'] === null) <=> ($b['provider'] === null);
            }

            return strcasecmp($a['provider']['name'], $b['provider']['name']);
        });

        return [
            'groups'             => $list,
            'custom_items_count' => $customItems,
            'total_products'     => array_sum(array_map(fn (array $g): int => count($g['products']), $list)),
        ];
    }
}
