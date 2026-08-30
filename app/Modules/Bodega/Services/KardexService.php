<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-467→472 (REQ-397→402) — único punto de escritura del Kardex. Mueve stock Y registra el
 * movimiento de forma atómica (misma transacción): ningún otro código en el sistema debe
 * incrementar/decrementar `product_warehouse_stock.quantity` directamente, para que el Kardex
 * sea siempre un reflejo fiel de la realidad (REQ-402) y cada línea cuadre matemáticamente
 * (`saldo_inicial + cantidad = saldo_resultante`, REQ-400/HU-400).
 *
 * RN2-RN5 de REQ-402 (Salida desde Pedidos Despachado, Ajuste desde aprobación, Reubicación
 * — 2 líneas simultáneas, Devolución desde recepción física) quedan sin conectar todavía porque
 * esas pantallas no existen aún (bloques 1/2/3/6/7 del roadmap de Bodega) — cuando se construyan,
 * llaman a `registrar()` en vez de tocar `ProductWarehouseStock` directo.
 *
 * SCRUM-429 (REQ-359, rebote de Daniela Amaya 2026-08-13) — bug real encontrado: este método SOLO
 * actualizaba `product_warehouse_stock.quantity` (desglose por bodega), nunca el agregado
 * `catalog_products.stock_quantity` del que derivan "Disponible"/"Stock Total" en TODO el resto
 * de Inventario/Compras/Bodega (`disponible = stock_quantity - por_servir`, ver
 * `InventoryController`/`WarehouseController`/`BodegaInventoryKpiService`, etc.) — un ajuste
 * aprobado se reflejaba en el desglose por bodega pero el agregado quedaba desfasado
 * indefinidamente. Fix: sincronizar el agregado con el mismo delta firmado, en la misma
 * transacción — `GoodsReceiptController`/`GoodsReceiptConfirmationService` ya tocan esta misma
 * columna directamente (no vía Kardex, esos flujos no llaman a `registrar()`), mismo patrón
 * `COALESCE(stock_quantity, 0) + delta` para tolerar NULL.
 */
final class KardexService
{
    /**
     * @param int         $catalogProductId
     * @param int         $warehouseId
     * @param string      $tipo             Uno de InventoryMovement::TIPO_*
     * @param int         $cantidad         Con signo — positivo entradas, negativo salidas
     * @param string      $referenciaTexto  Ej. "Orden #123 recibida"
     * @param string|null $referenciaEntidadTipo
     * @param int|null    $referenciaEntidadId
     * @param int|null    $realizadoPor     Null = "Sistema" (ver Modelo de Datos, realizado_por)
     */
    public function registrar(
        int $catalogProductId,
        int $warehouseId,
        string $tipo,
        int $cantidad,
        string $referenciaTexto,
        ?string $referenciaEntidadTipo = null,
        ?int $referenciaEntidadId = null,
        ?int $realizadoPor = null,
    ): InventoryMovement {
        return DB::transaction(function () use (
            $catalogProductId, $warehouseId, $tipo, $cantidad, $referenciaTexto,
            $referenciaEntidadTipo, $referenciaEntidadId, $realizadoPor,
        ): InventoryMovement {
            /** @var ProductWarehouseStock $stock */
            $stock = ProductWarehouseStock::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['catalog_product_id' => $catalogProductId, 'warehouse_id' => $warehouseId],
                    ['quantity' => 0],
                );

            $saldoInicial = $stock->quantity;
            $saldoResultante = $saldoInicial + $cantidad;

            $stock->update(['quantity' => $saldoResultante]);

            // SCRUM-429 — mismo delta firmado, aplicado al agregado del producto.
            CatalogProduct::where('id', $catalogProductId)
                ->update(['stock_quantity' => DB::raw('COALESCE(stock_quantity, 0) + ('.$cantidad.')')]);

            return InventoryMovement::create([
                'catalog_product_id'      => $catalogProductId,
                'warehouse_id'            => $warehouseId,
                'tipo'                    => $tipo,
                'cantidad'                => $cantidad,
                'referencia_texto'        => $referenciaTexto,
                'referencia_entidad_tipo' => $referenciaEntidadTipo,
                'referencia_entidad_id'   => $referenciaEntidadId,
                'realizado_por'           => $realizadoPor,
                'saldo_inicial'           => $saldoInicial,
                'saldo_resultante'        => $saldoResultante,
                'created_at'              => now(),
            ]);
        });
    }
}
