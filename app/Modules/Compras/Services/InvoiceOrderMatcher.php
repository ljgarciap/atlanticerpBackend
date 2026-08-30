<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\FuzzySearchService;

/**
 * SCRUM-224 (ADR-SCRUM224, punto 2) — matching determinístico en PHP entre lo que Claude
 * EXTRAJO del documento (hechos observables: referencia de orden, nombre de proveedor,
 * líneas de producto) y las órdenes/productos REALES del tenant. El modelo nunca ve ni elige
 * un ID real — esto es lo que reemplaza esa elección, así que ninguna alucinación o texto
 * inyectado en el documento puede apuntar a una orden que no coincide de verdad.
 */
final class InvoiceOrderMatcher
{
    public function __construct(private readonly FuzzySearchService $fuzzy)
    {
    }

    /**
     * @param array{
     *     detected_order_reference?: string|null,
     *     detected_provider_name?: string|null,
     *     line_items?: list<array{reference?: string|null, description?: string, quantity?: float|int|null, unit_cost?: float|int|null}>,
     *     confidence?: string,
     * } $extracted
     * @return array{
     *     matched_order_id: int|null,
     *     matched_provider_id: int|null,
     *     lines: list<array{catalog_product_id: int, quantity: float|null, unit_cost: float|null}>,
     *     unmatched_count: int,
     *     confidence: string|null,
     *     detected_order_reference: string|null,
     *     detected_provider_name: string|null,
     * }
     */
    public function match(array $extracted): array
    {
        $lineItems = $extracted['line_items'] ?? [];

        $order = $this->matchOrder(
            $extracted['detected_order_reference'] ?? null,
            $extracted['detected_provider_name'] ?? null,
        );

        $matchedLines   = [];
        $unmatchedCount = count($lineItems);

        if ($order !== null) {
            $productMatch   = $this->matchProducts($order, $lineItems);
            $matchedLines   = $productMatch['matched'];
            $unmatchedCount = $productMatch['unmatched_count'];
        }

        return [
            'matched_order_id'         => $order?->id,
            'matched_provider_id'      => $order?->provider_id,
            'lines'                    => $matchedLines,
            'unmatched_count'          => $unmatchedCount,
            'confidence'               => $extracted['confidence'] ?? null,
            'detected_order_reference' => $extracted['detected_order_reference'] ?? null,
            'detected_provider_name'   => $extracted['detected_provider_name'] ?? null,
        ];
    }

    /**
     * REQ-162 RN — mismas órdenes elegibles que `GoodsReceiptController::eligibleOrders()`
     * (excluye "Recibido"): una orden ya recibida no acepta más ingresos, así que no tiene
     * sentido precargarla aunque el documento la mencione.
     */
    private function matchOrder(?string $reference, ?string $providerName): ?PurchaseOrder
    {
        /** @var \Illuminate\Support\Collection<int, PurchaseOrder> $eligible */
        $eligible = PurchaseOrder::query()
            ->with('provider')
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->get();

        if ($eligible->isEmpty()) {
            return null;
        }

        if ($reference !== null && trim($reference) !== '') {
            $numeric = preg_replace('/\D+/', '', $reference) ?? '';
            if ($numeric !== '') {
                $byId = $eligible->firstWhere('id', (int) $numeric);
                if ($byId !== null) {
                    return $byId;
                }
            }
        }

        if ($providerName !== null && trim($providerName) !== '') {
            [$matches] = $this->fuzzy->search(
                array_values($eligible->all()),
                $providerName,
                fn (PurchaseOrder $o): string => $o->provider !== null ? $o->provider->name : '',
            );

            // Heurística del ADR: solo se usa si resuelve a EXACTAMENTE 1 proveedor con
            // EXACTAMENTE 1 orden elegible abierta — 2+ candidatas es ambigüedad real, se deja
            // sin detectar en vez de adivinar.
            $matchedProviderIds = collect($matches)->pluck('provider_id')->unique();
            if ($matchedProviderIds->count() === 1) {
                $ordersForProvider = $eligible->where('provider_id', $matchedProviderIds->first())->values();
                if ($ordersForProvider->count() === 1) {
                    return $ordersForProvider->first();
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{reference?: string|null, description?: string, quantity?: float|int|null, unit_cost?: float|int|null}> $lineItems
     * @return array{matched: list<array{catalog_product_id: int, quantity: float|null, unit_cost: float|null}>, unmatched_count: int}
     */
    private function matchProducts(PurchaseOrder $order, array $lineItems): array
    {
        $order->loadMissing('lines.catalogProduct');

        // Mismo alcance que REQ-163 (el buscador de agregar producto ya acota a lo esperado de
        // la orden) — nunca se le agregan a la precarga productos que la orden no esperaba.
        /** @var list<CatalogProduct> $expectedProducts */
        $expectedProducts = $order->lines
            ->filter(fn ($line) => $line->catalog_product_id !== null && $line->catalogProduct !== null)
            ->pluck('catalogProduct')
            ->unique(fn (CatalogProduct $p) => $p->id)
            ->values()
            ->all();

        $matched          = [];
        $matchedProductIds = [];
        $unmatchedCount   = 0;

        foreach ($lineItems as $item) {
            $needle = trim((string) ($item['reference'] ?? ''));
            if ($needle === '') {
                $needle = trim((string) ($item['description'] ?? ''));
            }

            $candidates = array_values(array_filter(
                $expectedProducts,
                fn (CatalogProduct $p) => ! in_array($p->id, $matchedProductIds, true),
            ));

            if ($needle === '' || $candidates === []) {
                $unmatchedCount++;

                continue;
            }

            [$results] = $this->fuzzy->search(
                $candidates,
                $needle,
                fn (CatalogProduct $p): string => trim(($p->reference ?? '') . ' ' . ($p->name ?? '')),
            );

            if ($results === []) {
                $unmatchedCount++;

                continue;
            }

            $product              = $results[0];
            $matchedProductIds[]  = $product->id;
            $matched[] = [
                'catalog_product_id' => $product->id,
                'quantity'           => isset($item['quantity']) ? (float) $item['quantity'] : null,
                'unit_cost'          => isset($item['unit_cost']) ? (float) $item['unit_cost'] : null,
            ];
        }

        return ['matched' => $matched, 'unmatched_count' => $unmatchedCount];
    }
}
