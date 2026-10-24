<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use Illuminate\Support\Collection;

/**
 * SCRUM-930 — extraído de `AccountStatementService::resolveGuiaEntrega()` (mismo comportamiento,
 * pura extracción) para que SCRUM-931 (detalle de Cobro) pueda resolver la misma guía sin duplicar
 * la lógica una segunda vez. El sistema no tiene folio de negocio propio para la guía — el número
 * es sintético (`"{order_number}-G"` del pedido que efectivamente tiene el documento, no
 * necesariamente el `order_id` de la factura).
 */
final class GuiaEntregaResolver
{
    /**
     * El `order_id` que tiene el `OrderDocument` de guía no necesariamente es `$invoice->order_id`
     * (una factura `completo` cubre a toda la familia, SCRUM-876) ni está necesariamente en
     * `$ordersById` (esa colección puede solo traer los `order_id` que aparecen DIRECTAMENTE en
     * alguna factura del cliente/proyecto — no toda la familia), de ahí el fallback a
     * `Order::find()`.
     *
     * @param Collection<int, Order> $ordersById
     * @return array{0: ?string, 1: ?int} [numero_sintético "{order_number}-G", order_id real]
     */
    public static function resolve(AdminContInvoice $invoice, ?Order $order, Collection $ordersById): array
    {
        if ($order === null) {
            return [null, null];
        }

        $candidateIds = $invoice->tipo === AdminContInvoice::TIPO_PARCIAL ? [$order->id] : $order->familyMemberIds();

        $guiaOrderId = OrderDocument::query()
            ->whereIn('order_id', $candidateIds)
            ->where('tipo', OrderDocument::TIPO_GUIA_ENTREGA)
            ->value('order_id');

        if ($guiaOrderId === null) {
            return [null, null];
        }

        /** @var Order|null $guiaOrder */
        $guiaOrder = $ordersById->get($guiaOrderId) ?? Order::find($guiaOrderId);

        return $guiaOrder !== null ? ["{$guiaOrder->order_number}-G", $guiaOrder->id] : [null, null];
    }
}
