<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Bloque B2 (REQ-346/351, Ver Inventario de Bodega) — KPIs y estado de stock para la
 * pantalla de Bodega, con el mismo criterio de umbral que Compras (`InventoryKpiService::
 * stockState()`, función pura parametrizada por un int de "por servir" — SIEMPRE reusada tal
 * cual, nunca reimplementada) pero alimentado por `OrderCommitmentService::committedMap()`
 * (compromiso a PEDIDOS de Bodega en curso) en vez de `InventoryKpiService::porServirMap()`
 * (reservas de Ventas & Diseño al momento de comprar — un concepto distinto, ver docblock de
 * `OrderCommitmentService`).
 *
 * REQ-351 RN1 confirma que este es el "por servir" correcto para esta pantalla: "Dado que un
 * producto tiene 2 unidades disponibles y 1 comprometida A UN PEDIDO, Stock Total indica 3" — "un
 * pedido" es `Bodega\Models\Order`, no una línea de compra.
 *
 * NOTA — "En atención" (REQ-346 RN1 / SCRUM-416, chip REQ-350 / SCRUM-420) NO reusa
 * `InventoryKpiService::isInAttention()`. Pre-QA (2026-07-28,
 * docs/pre-qa/scrum416-420-en-atencion-rotacion-20260728.md) encontró que ese método implementa
 * el criterio BLOCKLIST de REQ-169 (Compras > Inventario, en producción: cuenta todo excepto
 * `rotation=baja`, `NULL` incluido — conservador, a propósito). REQ-346 RN1 define un criterio
 * ALLOWLIST distinto para Bodega: "productos de rotación ALTA o MEDIA que además están en Bajo
 * stock o Sin stock" — `NULL`/sin clasificar NO cuenta. Decisión de Luis: separar la lógica por
 * pantalla en vez de unificar, para no regresionar REQ-169 (ya en producción, depende del
 * blocklist). Ver `isInAttentionBodega()` abajo.
 */
final class BodegaInventoryKpiService
{
    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OrderCommitmentService $commitments,
    ) {}

    /**
     * REQ-346 RN1 / SCRUM-416 (KPI) y REQ-350 / SCRUM-420 (chip "Atención") — criterio propio de
     * Bodega, ALLOWLIST: solo rotación `alta` o `media`, Y ADEMÁS en "Bajo stock" o "Sin stock"
     * (`disponible <= reorder_point`, mismo umbral que `InventoryKpiService::stockState()`).
     * `rotation === null` ("Compra única" en el frontend, ver REQ-419) NUNCA cuenta acá — a
     * diferencia de `InventoryKpiService::isInAttention()` (Compras, REQ-169), que sí lo cuenta.
     * `reorder_point === null` se trata como 0 (mismo fallback que el resto del servicio) — no
     * rompe el cálculo, simplemente exige `disponible <= 0` (Sin stock) para calificar.
     */
    public function isInAttentionBodega(CatalogProduct $product, int $committed): bool
    {
        if (! in_array($product->rotation, [CatalogProduct::ROTATION_ALTA, CatalogProduct::ROTATION_MEDIA], true)) {
            return false;
        }

        $disponible = ($product->stock_quantity ?? 0) - $committed;

        return $disponible <= ($product->reorder_point ?? 0);
    }

    /** @return array{total_products: int, low_stock: int, out_of_stock: int, in_attention: int, total_por_servir: int} */
    public function kpis(): array
    {
        /** @var Collection<int, CatalogProduct> $active */
        $active = CatalogProduct::where('is_active', true)->get();

        $committedMap = $this->commitments->committedMap(
            array_values(array_map('intval', $active->pluck('id')->all()))
        );

        $lowStock = 0;
        $outOfStock = 0;
        $inAttention = 0;
        $totalPorServir = 0;

        foreach ($active as $product) {
            $committed = $committedMap[$product->id] ?? 0;
            $totalPorServir += $committed;

            $state = $this->inventoryKpis->stockState($product, $committed);
            if ($state === InventoryKpiService::STOCK_SIN_STOCK) {
                $outOfStock++;
            } elseif ($state === InventoryKpiService::STOCK_BAJO_STOCK) {
                $lowStock++;
            }

            if ($this->isInAttentionBodega($product, $committed)) {
                $inAttention++;
            }
        }

        return [
            'total_products'   => $active->count(),
            'low_stock'        => $lowStock,
            'out_of_stock'     => $outOfStock,
            'in_attention'     => $inAttention,
            'total_por_servir' => $totalPorServir,
        ];
    }
}
