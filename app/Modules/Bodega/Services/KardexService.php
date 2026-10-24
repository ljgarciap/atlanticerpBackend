<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Exceptions\InsufficientStockException;
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
 * SCRUM-429 (REQ-359, rebote de Gerencia Test 2026-08-13) — bug real encontrado: este método SOLO
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
     * @param  string  $tipo  Uno de InventoryMovement::TIPO_*
     * @param  int  $cantidad  Con signo — positivo entradas, negativo salidas
     * @param  string  $referenciaTexto  Ej. "Orden #123 recibida"
     * @param  int|null  $realizadoPor  Null = "Sistema" (ver Modelo de Datos, realizado_por)
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

            // SCRUM-837 — una Reubicación nunca debe dejar la bodega de ORIGEN en negativo: es la
            // señal de que se está moviendo stock que en realidad nunca estuvo ahí (bug real:
            // `SyncInsumoReservationOnPurchaseOrderReceived` reubicaba a ciegas desde Bodega
            // Central sin verificar dónde había caído la Entrada real). El resto de tipos NO
            // comparten este piso a propósito, pero por razones distintas entre sí — no asumir
            // que todos "ya validan en su caller":
            //   - Entrada: siempre positiva, nunca puede dejar saldo negativo.
            //   - Salida/Ajuste: cada caller valida "stock suficiente" antes de llegar acá con su
            //     propia regla de negocio (ver `RelocationRequestController`, que topa la cantidad
            //     al stock disponible antes de crear la solicitud de reubicación).
            //   - Consumo: NO valida nada hoy (`InspectionReportService::
            //     consumeMaterialsFromReserve()` resta lo reportado por el técnico sin chequear
            //     disponible) — a diferencia de los otros, este es a propósito: decisión de Luis
            //     (daily 2026-08-18, SCRUM-361) de permitir que el consumo de insumos deje el
            //     disponible negativo sin bloquear el guardado del Informe de Inspección, avisando
            //     solo visualmente (ícono + tooltip en `InsumoTable.tsx`, frontend). Sin test de
            //     regresión que fije este comportamiento — ver
            //     `InspectionReportInsumoConsumptionTest::test_consumir_mas_de_lo_disponible_deja_saldo_negativo_sin_bloquear`.
            if ($tipo === InventoryMovement::TIPO_REUBICACION && $saldoResultante < 0) {
                throw new InsufficientStockException(
                    "No hay stock suficiente para reubicar: producto {$catalogProductId}, bodega "
                    ."{$warehouseId}, saldo actual {$saldoInicial}, se intentó aplicar {$cantidad}."
                );
            }

            // SCRUM-1196 — un despacho de pedido (Salida con referencia PEDIDO) tampoco puede dejar la bodega en negativo: el tope real está en el
            // picking (nunca se alista más de lo que tiene una bodega), y esto es la defensa si el saldo cambió entre el picking y el despacho.
            if ($tipo === InventoryMovement::TIPO_SALIDA && $referenciaEntidadTipo === 'PEDIDO' && $saldoResultante < 0) {
                throw new InsufficientStockException(
                    "No hay stock suficiente para despachar: producto {$catalogProductId}, bodega "
                    ."{$warehouseId}, saldo actual {$saldoInicial}, se intentó aplicar {$cantidad}."
                );
            }

            $stock->update(['quantity' => $saldoResultante]);

            // SCRUM-429 — mismo delta firmado, aplicado al agregado del producto.
            CatalogProduct::where('id', $catalogProductId)
                ->update(['stock_quantity' => DB::raw('COALESCE(stock_quantity, 0) + ('.$cantidad.')')]);

            return InventoryMovement::create([
                'catalog_product_id' => $catalogProductId,
                'warehouse_id' => $warehouseId,
                'tipo' => $tipo,
                'cantidad' => $cantidad,
                'referencia_texto' => $referenciaTexto,
                'referencia_entidad_tipo' => $referenciaEntidadTipo,
                'referencia_entidad_id' => $referenciaEntidadId,
                'realizado_por' => $realizadoPor,
                'saldo_inicial' => $saldoInicial,
                'saldo_resultante' => $saldoResultante,
                'created_at' => now(),
            ]);
        });
    }
}
