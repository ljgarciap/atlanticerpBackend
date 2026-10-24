<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services\Billing;

/**
 * SCRUM-929 (B2) — resultado de `InvoiceBillingPlanner`: lo que se facturaría (o se factura) para
 * una entrega o para el pedido completo. Sin efectos: sirve igual para la vista previa que para
 * persistir la factura y sus líneas.
 *
 * Cada línea trae los importes que se persisten en `invoice_lines` (`subtotal` base neto de TODOS
 * los descuentos, `itbms`, `total`) más `bruto`, un dato solo de presentación (importe proporcional
 * antes del descuento de Totales de la cotización) que NO se guarda.
 *
 * @phpstan-type PlanLine array{quote_item_id: int, order_item_id: int|null, descripcion: string, cantidad: float, precio_unitario: float, subtotal: float, itbms: float, total: float, bruto: float}
 */
final class InvoicePlan
{
    /**
     * @param list<PlanLine> $lines
     * @param list<string>   $advertencias
     */
    public function __construct(
        public readonly string $tipo,
        public readonly int $orderId,
        public readonly int $familyRootId,
        public readonly float $monto,
        public readonly array $lines,
        public readonly bool $cierraPedido,
        public readonly float $restanteDespues,
        /** Avisos para quien emite (p. ej. el total de la tarjeta no cuadra con la cotización recalculada). Vacío = nada que revisar. */
        public readonly array $advertencias = [],
    ) {}
}
