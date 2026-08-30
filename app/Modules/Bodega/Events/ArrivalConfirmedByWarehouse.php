<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Bloque B2 (REQ-357, alcance reducido) — se dispara cuando Bodega reporta la llegada
 * física de mercancía "Por ingresar" (`ArrivalConfirmationService::confirmArrival()`), ANTES de
 * que Compras finalice el ingreso al inventario (`GoodsReceiptController::confirm()`, que sigue
 * sin escuchar este evento — se factura por el flujo ya existente, no por un listener nuevo).
 *
 * Hoy no tiene ningún listener real — mismo alcance reducido que
 * `Compras\Events\OrderLiquidationAssigned` (ver CLAUDE.md): el consumidor natural sería una
 * notificación a Compras ("Bodega ya contó la mercancía, finalizá el ingreso"), pero eso no está
 * en el alcance de este batch — el evento ya queda disponible para que ese futuro listener no
 * requiera tocar este service de nuevo.
 */
final class ArrivalConfirmedByWarehouse implements DomainEvent
{
    public function __construct(
        public readonly int    $catalogProductId,
        public readonly int    $confirmedQuantity,
        public readonly int    $confirmedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.arrival.confirmed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'catalog_product_id' => $this->catalogProductId,
            'confirmed_quantity' => $this->confirmedQuantity,
            'confirmed_by'       => $this->confirmedBy,
            'tenant'             => $this->tenant,
        ];
    }
}
