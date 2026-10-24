<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCommissionAdjustment;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;

/**
 * SCRUM-1168 F4 — descuento de comisión por reverso de un cobro. Si reversar un cobro (anular, desaplicar) deja SIN COBRAR un pedido cuya comisión interna ya
 * estaba liquidada (`pagado`), no se recalcula el mes cerrado ni se toca su snapshot: se registra un descuento por el monto que se pagó de ese pedido,
 * que se resta en la siguiente liquidación del vendedor (`CommissionOrderService::settleInternalCommissionsToCashLedger`).
 *
 * Uso: `estadoPrevio()` ANTES de reversar y `registrarDespues()` DESPUÉS (dentro de la misma transacción). El cálculo recorre los pedidos de las familias
 * involucradas; los reversos son raros, así que no se optimiza más.
 */
final class CommissionAdjustmentService
{
    /**
     * Pedidos de estas facturas cuya comisión ya está liquidada, con lo que se pagó de cada uno.
     *
     * @param list<int> $invoiceIds
     * @return array<string, array{pedido_id: int, vendedor_id: int, monto: float}> clave "pedido:vendedor"
     */
    public function estadoPrevio(array $invoiceIds): array
    {
        $familias = $this->familias($invoiceIds);
        if ($familias === []) {
            return [];
        }

        /** @var CommissionOrderService $svc */
        $svc = app()->make(CommissionOrderService::class);
        $out = [];
        foreach ($svc->ordersForAllVendors()->filter(fn (array $o): bool => in_array($o['pedido_id'], $familias, true) && $o['estado'] === CommissionOrderService::ESTADO_PAGADO) as $order) {
            $monto = $svc->montoComisionDe($order, $svc->ordersForVendor($order['vendedor_id']));
            if ($monto > 0.004) {
                $out["{$order['pedido_id']}:{$order['vendedor_id']}"] = ['pedido_id' => $order['pedido_id'], 'vendedor_id' => $order['vendedor_id'], 'monto' => $monto];
            }
        }

        return $out;
    }

    /**
     * Compara con el estado actual: los pedidos que estaban liquidados y ya no lo están generan un descuento (uno solo por pedido y vendedor mientras
     * siga sin descontarse).
     *
     * @param array<string, array{pedido_id: int, vendedor_id: int, monto: float}> $previo
     * @return list<array{vendedor_id: int, pedido_id: int, monto: float}> los descuentos creados
     */
    public function registrarDespues(array $previo, string $motivo, ?int $movementId): array
    {
        if ($previo === []) {
            return [];
        }

        /** @var CommissionOrderService $svc */
        $svc     = app()->make(CommissionOrderService::class);   // instancia nueva: sin la memoria de pedidos de antes del reverso
        $actuales = $svc->ordersForAllVendors();
        $creados = [];

        foreach ($previo as $p) {
            $order = $actuales->first(fn (array $o): bool => $o['pedido_id'] === $p['pedido_id'] && $o['vendedor_id'] === $p['vendedor_id']);
            if ($order === null || $order['estado'] === CommissionOrderService::ESTADO_PAGADO) {
                continue;   // sigue cobrado: la comisión pagada se mantiene
            }

            $yaPendiente = AdminContCommissionAdjustment::query()
                ->where('vendedor_id', $p['vendedor_id'])->where('pedido_id', $p['pedido_id'])->whereColumn('monto_aplicado', '<', 'monto')->exists();
            if ($yaPendiente) {
                continue;
            }

            AdminContCommissionAdjustment::create([
                'vendedor_id' => $p['vendedor_id'], 'pedido_id' => $p['pedido_id'], 'monto' => $p['monto'], 'motivo' => $motivo, 'source_movement_id' => $movementId,
            ]);
            $creados[] = ['vendedor_id' => $p['vendedor_id'], 'pedido_id' => $p['pedido_id'], 'monto' => $p['monto']];
        }

        return $creados;
    }

    /**
     * @param list<int> $invoiceIds
     * @return list<int> `pedido_id` de las filas de comisión (la tarjeta de Pipeline) de las familias de esas facturas
     */
    private function familias(array $invoiceIds): array
    {
        $raices = AdminContInvoice::query()->whereIn('id', $invoiceIds)->get()
            ->map(fn (AdminContInvoice $i): int => (int) ($i->family_root_id ?? $i->order_id))->unique()->values()->all();

        return array_values(array_unique(array_map(
            'intval',
            Order::query()->whereIn('id', $raices)->whereNotNull('pipeline_card_id')->pluck('pipeline_card_id')->all(),
        )));
    }
}
