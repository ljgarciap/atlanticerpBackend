<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Servicios\Models\InsumoPurchaseRequest;
use App\Modules\Servicios\Models\InsumoSetting;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-273→275/291, SCRUM-343/344/345/361) — "Reserva
 * Servicios" de insumos. Ver docs/architecture/servicios-fase4-diseno.md sección 8. Reutiliza TODA
 * la infraestructura de inventario ya construida en Sprint 2/Bodega (SCRUM-451→472): la Reserva
 * Servicios es una ubicación más en `bodega.product_warehouse_stock`, no una tabla de stock
 * propia (REQ-291 RN1). `insumo_settings` es la única tabla nueva de configuración ("qué producto
 * trackea Servicios + su mínimo local"); `insumo_purchase_requests` es un puente de trazabilidad
 * local hacia la `PurchaseOrder` real de Compras (REQ-274 — "mismo ciclo de estados reales que
 * Compras", nunca una tabla de stock interna paralela).
 *
 * El "Consumo" (REQ-291 RN3(c)/RN4) se dispara desde `InspectionReportService`, no desde acá — ver
 * su docblock. El "Entrada + Reubicación" (REQ-277) se dispara desde
 * `App\Modules\Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived`, no desde acá.
 */
final class InsumoService
{
    public const RESERVA_WAREHOUSE_NAME = 'Reserva Servicios';

    public const BODEGA_CENTRAL_WAREHOUSE_NAME = 'Bodega Central';

    /**
     * REQ-273 — listado. `disponible` viene de `product_warehouse_stock` en la bodega "Reserva
     * Servicios" (REQ-291 RN2) — única fuente de verdad, nunca una columna propia que pueda
     * desincronizarse del Kardex real. `estado` es "ok" si `disponible >= minimo`, "bajo_minimo"
     * si no — el botón "Solicitar" del frontend NUNCA se gatea con este estado (RN explícita del
     * ticket), esto es solo información.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $reservaId = $this->reservaWarehouseId();

        /** @var \Illuminate\Database\Eloquent\Collection<int, InsumoSetting> $settings */
        $settings = InsumoSetting::query()->with('catalogProduct')->orderBy('id')->get();
        if ($settings->isEmpty()) {
            return [];
        }

        /** @var \Illuminate\Support\Collection<int, int> $stockByProduct catalog_product_id => quantity */
        $stockByProduct = ProductWarehouseStock::query()
            ->where('warehouse_id', $reservaId)
            ->whereIn('catalog_product_id', $settings->pluck('catalog_product_id'))
            ->pluck('quantity', 'catalog_product_id')
            ->map(fn ($q) => (int) $q);

        // REQ-274 RN4 ("el botón vuelve a mostrar 'Solicitar' recién al llegar a Recibido"):
        // el frontend necesita saber, por fila, si ya hay una solicitud PENDIENTE activa — sin
        // esto no hay forma de reflejar ese RN tras un reload. `InsumoPurchaseRequest` referencia
        // `catalog_product_id`, no `insumo_setting_id` (ver migración), así que se indexa por ahí.
        $pendingByProduct = InsumoPurchaseRequest::query()
            ->where('estado', InsumoPurchaseRequest::ESTADO_PENDIENTE)
            ->whereIn('catalog_product_id', $settings->pluck('catalog_product_id'))
            ->pluck('catalog_product_id')
            ->flip();

        return $settings->map(function (InsumoSetting $s) use ($stockByProduct, $pendingByProduct): array {
            $disponible = (int) ($stockByProduct[$s->catalog_product_id] ?? 0);
            $estadoSolicitud = $pendingByProduct->has($s->catalog_product_id)
                ? InsumoPurchaseRequest::ESTADO_PENDIENTE
                : null;

            return $this->formatSetting($s, $disponible, $estadoSolicitud);
        })->values()->all();
    }

    /**
     * REQ-275 — alta de un insumo nuevo trackeado por la Reserva Servicios. RN2: solo productos
     * reales del Catálogo (garantizado por la FK de la migración — nunca un producto libre). RN
     * implícita ("excluye insumos que ya están en la lista"): un producto solo puede trackearse
     * una vez, ver `unique` de la migración. Al confirmar, dispara automáticamente una solicitud
     * de compra (REQ-274) por `cantidadInicial` — arranca en 0 disponible hasta que se reciba.
     *
     * @throws \InvalidArgumentException el producto ya está trackeado, o no tiene proveedor asignado
     * @return array<string, mixed>
     */
    public function addInsumo(int $catalogProductId, int $minimo, int $cantidadInicial, User $user): array
    {
        return DB::transaction(function () use ($catalogProductId, $minimo, $cantidadInicial, $user): array {
            $yaExiste = InsumoSetting::where('catalog_product_id', $catalogProductId)->lockForUpdate()->exists();
            if ($yaExiste) {
                throw new \InvalidArgumentException('Este producto ya está en la lista de Insumos de Servicios.');
            }

            $setting = InsumoSetting::create([
                'catalog_product_id' => $catalogProductId,
                'minimo'             => $minimo,
            ]);

            $this->requestPurchase($setting, $cantidadInicial, $user);

            return $this->formatSetting(
                $setting->fresh(['catalogProduct']) ?? $setting,
                0,
                InsumoPurchaseRequest::ESTADO_PENDIENTE,
            );
        });
    }

    /**
     * REQ-274 — genera una `PurchaseOrder` REAL en Compras, siguiendo el mismo ciclo de estados
     * que el resto del módulo (RN explícita del ticket — nunca una tabla interna aparte).
     * `origin_module = ORIGIN_SERVICIOS` la distingue del resto de órdenes para que
     * `SyncInsumoReservationOnPurchaseOrderReceived` sepa reaccionar cuando llegue a "Recibido".
     *
     * Sin selección de proveedor en el formulario (REQ-274 RN: "pide cantidad antes de enviar",
     * nada más) — se deriva del proveedor por defecto del producto
     * (`catalog_products.provider_id`), mismo dato que ya usa Compras para reordenar este
     * producto. `shipping_type`/`who_pays_shipping` quedan NULL a propósito — sin esos datos
     * `PurchaseOrder::computeRequiresMarkApproval()` siempre da `false` (RN3/REQ-118 solo aplica a
     * envíos aéreos pagados por Atlantic, que este flujo interno nunca captura), así que la
     * orden nace directo en "Pendiente/Por liquidar", sin bloquear en un gate de aprobación de
     * Mark que este formulario no tiene forma de satisfacer.
     *
     * @throws \InvalidArgumentException el producto del insumo ya no existe, o no tiene proveedor asignado
     */
    public function requestPurchase(InsumoSetting $setting, int $cantidad, User $user): InsumoPurchaseRequest
    {
        return DB::transaction(function () use ($setting, $cantidad, $user): InsumoPurchaseRequest {
            /** @var CatalogProduct|null $product */
            $product = CatalogProduct::find($setting->catalog_product_id);
            if ($product === null) {
                throw new \InvalidArgumentException('El producto de este insumo ya no existe en el catálogo.');
            }
            if ($product->provider_id === null) {
                throw new \InvalidArgumentException(
                    'Este insumo no tiene un proveedor asignado en el Catálogo — asígnalo desde Compras antes de solicitar.'
                );
            }

            $unitCost = (float) ($product->cost ?? 0);
            $subtotal = round($unitCost * $cantidad, 2);

            $order = PurchaseOrder::create([
                'provider_id'            => $product->provider_id,
                'status'                 => PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR,
                'status_changed_at'      => now(),
                'modality'               => PurchaseOrder::MODALITY_DIRECTO,
                'requires_mark_approval' => false,
                'currency'               => 'USD',
                'total_amount'           => $subtotal,
                'notes'                  => "Solicitud de Reserva Servicios — {$product->description}",
                'created_by'             => $user->id,
                'origin_module'          => PurchaseOrder::ORIGIN_SERVICIOS,
            ]);

            PurchaseOrderLine::create([
                'purchase_order_id'  => $order->id,
                'catalog_product_id' => $product->id,
                'quantity'           => $cantidad,
                'unit_cost'          => $unitCost,
                'subtotal'           => $subtotal,
            ]);

            return InsumoPurchaseRequest::create([
                'catalog_product_id'  => $product->id,
                'cantidad_solicitada' => $cantidad,
                'estado'              => InsumoPurchaseRequest::ESTADO_PENDIENTE,
                'purchase_order_id'   => $order->id,
                'solicitado_por'      => $user->id,
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function presentPurchaseRequest(InsumoPurchaseRequest $r): array
    {
        return [
            'id'                  => $r->id,
            'catalog_product_id'  => $r->catalog_product_id,
            'cantidad_solicitada' => $r->cantidad_solicitada,
            'estado'              => $r->estado,
            'purchase_order_id'   => $r->purchase_order_id,
            'received_at'         => $r->received_at?->toIso8601String(),
            'created_at'          => $r->created_at->toIso8601String(),
        ];
    }

    public function reservaWarehouseId(): int
    {
        return (int) Warehouse::where('name', self::RESERVA_WAREHOUSE_NAME)->value('id');
    }

    public function bodegaCentralWarehouseId(): int
    {
        return (int) Warehouse::where('name', self::BODEGA_CENTRAL_WAREHOUSE_NAME)->value('id');
    }

    /** @return array<string, mixed> */
    private function formatSetting(InsumoSetting $s, int $disponible, ?string $estadoSolicitud = null): array
    {
        $product = $s->catalogProduct;

        // Sin "?->" a propósito: "??" ya evalúa la cadena completa en modo isset-safe (no dispara
        // "member access on null" aunque $product sea null) — mismo criterio ya usado en
        // ToolService::summary().
        return [
            'id'                 => $s->id,
            'catalog_product_id' => $s->catalog_product_id,
            'referencia'         => $product->factory_reference ?? $product->reference ?? null,
            // SCRUM-361/779 (rebote QA 2026-08-19) — la migración 2026_08_15_100000 movió el
            // nombre visible del producto a la columna `name` y reseteó `description` a '' —
            // este mapeo quedó leyendo el campo viejo, devolviendo nombre vacío tras la migración
            // ICG (SCRUM-750/751). ProductLookupController::format() ya usa `name` correctamente.
            'nombre'             => $product->name ?? null,
            'disponible'         => $disponible,
            'minimo'             => $s->minimo,
            'estado'             => $disponible >= $s->minimo ? 'ok' : 'bajo_minimo',
            // REQ-274 RN4 — null cuando no hay solicitud activa (el botón muestra "Solicitar");
            // 'pendiente' mientras la PurchaseOrder real no llegó a "Recibido".
            'estado_solicitud'   => $estadoSolicitud,
        ];
    }
}
