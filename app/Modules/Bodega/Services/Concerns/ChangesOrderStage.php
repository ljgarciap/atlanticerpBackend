<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services\Concerns;

use App\Modules\Bodega\Events\OrderStageChanged;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Shared\Multitenancy\Tenant;

/**
 * SCRUM-329 Oleada A / Batch A2 — escritura centralizada de `Order.stage` +
 * `order_stage_history` + `bodega.order.stage-changed`. Deliberadamente NO valida
 * `Order::STAGE_ORDER` acá (a diferencia de lo que podría esperarse de REQ-311 RN2) — varios
 * flujos de negocio reales mueven una tarjeta "hacia atrás" en la secuencia visual (ej. una
 * Revisión de Inventario que vuelve a "Asignado" sin stock, REQ-318 Escenario 2), así que la
 * validación de qué transición es válida vive en el método de negocio que llama a este helper
 * (cada uno conoce sus propias precondiciones), no acá.
 */
trait ChangesOrderStage
{
    /**
     * Senior Review 2026-07-22 (Blocker 1) — mismo patrón lock+recheck-dentro-de-la-transacción
     * que ya corrigió el CRÍTICO real de `AdjustmentRequestController::approve()`/`reject()`
     * (ver `AdjustmentRequestController.php:167-172`): todo método que transicione `Order.stage`
     * debe re-obtener el `Order` CON `lockForUpdate()` DENTRO de la transacción y volver a
     * validar su estado contra esa copia bloqueada — nunca confiar en el `$order` que llegó como
     * parámetro (cargado por el controller sin lock, potencialmente stale si otra request ya lo
     * mutó mientras esta esperaba abrir su propia transacción).
     */
    protected function lockOrder(int $orderId): Order
    {
        /** @var Order|null $order */
        $order = Order::where('id', $orderId)->lockForUpdate()->first();

        if ($order === null) {
            throw new OrderTransitionException('El pedido ya no existe.', status: 404);
        }

        return $order;
    }

    protected function transitionStage(Order $order, string $toStage, ?int $changedBy): void
    {
        $fromStage = $order->stage;

        $order->update(['stage' => $toStage]);

        OrderStageHistory::create([
            'order_id'   => $order->id,
            'from_stage' => $fromStage,
            'to_stage'   => $toStage,
            'changed_by' => $changedBy,
            'created_at' => now(),
        ]);

        event(new OrderStageChanged(
            orderId: $order->id,
            orderNumber: $order->order_number,
            fromStage: $fromStage,
            toStage: $toStage,
            changedBy: $changedBy,
            tenant: $this->requiredTenantSlug(),
        ));
    }

    protected function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
