<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-319) — "Sistema de Familia de Entregas": cuando un pedido se
 * divide en varias tarjetas (picking parcial REQ-317, revisión mixta REQ-318, entrega parcial en
 * Packing REQ-325), todas quedan vinculadas por `Order.family_id` a la tarjeta raíz
 * (`family_id = null`). Único punto de escritura de `family_id`/`sequence_in_family`/
 * `total_in_family` — ningún otro service debe tocar esas 3 columnas directamente.
 */
final class FamilyService
{
    /** @return Collection<int, Order> Todos los miembros de la familia (raíz + hijas), incl. el propio $order. */
    public function members(Order $order): Collection
    {
        $rootId = $order->familyRootId();

        return Order::where('id', $rootId)
            ->orWhere('family_id', $rootId)
            ->orderBy('sequence_in_family')
            ->orderBy('id')
            ->get();
    }

    /**
     * Vincula $child (YA creado con `family_id` seteado al mismo root que $anchor — ver nota
     * abajo) a la familia de $anchor y recalcula secuencia/total de TODA la familia.
     *
     * Importante: `family_id` del hijo debe fijarse EN el `Order::create()`, no en un `update()`
     * posterior — el índice único parcial `orders_pipeline_card_root_uq` (`WHERE family_id IS
     * NULL`) rechaza un INSERT con el mismo `pipeline_card_id` que la tarjeta original mientras
     * `family_id` siga en NULL, así sea solo por el instante entre el INSERT y un UPDATE separado
     * dentro de la misma transacción — Postgres valida constraints únicos por sentencia, no
     * diferido. Por eso este método NO asigna `family_id`, solo resecuencia — ver
     * `PickingService`/`InventoryReviewService`/`DeliveryService` para el patrón correcto:
     * `Order::create([..., 'family_id' => $anchor->familyRootId(), ...])` seguido de
     * `$this->family->attach($anchor, $child)`.
     */
    public function attach(Order $anchor, Order $child): void
    {
        $this->resequence($anchor->familyRootId());
    }

    private function resequence(int $rootId): void
    {
        $members = Order::where('id', $rootId)
            ->orWhere('family_id', $rootId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $total = $members->count();
        foreach ($members as $index => $member) {
            $member->update(['sequence_in_family' => $index + 1, 'total_in_family' => $total]);
        }
    }

    /**
     * REQ-319 RN2 — "si algún miembro todavía no llegó a Por despachar, todos muestran Pendiente;
     * solo cuando TODOS los miembros llegan ahí, todos cambian a Entrega completa al mismo tiempo".
     * `null` si el pedido no pertenece a ninguna familia (tarjeta única, sin badge que mostrar).
     */
    public function completionBadge(Order $order): ?string
    {
        if ($order->family_id === null && $order->total_in_family === null) {
            return null;
        }

        $reachedDespacho = [Order::STAGE_POR_DESPACHAR, Order::STAGE_DESPACHADO, Order::STAGE_ENTREGADO];

        $allReached = $this->members($order)->every(fn (Order $m): bool => in_array($m->stage, $reachedDespacho, true));

        return $allReached ? 'entrega_completa' : 'pendiente_falta_parte';
    }
}
