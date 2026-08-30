<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Listeners;

use App\Modules\Bodega\Services\OrderService;
use App\Modules\VentasDiseno\Events\QuoteApproved;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-308) — puente entre Ventas & Diseño y Bodega: cuando una
 * cotización pasa a "Aprobado" en el Pipeline, crea automáticamente la tarjeta de Pedido
 * correspondiente. Toda la lógica real vive en `OrderService::createFromApprovedQuote()` — este
 * listener solo delega, mismo patrón que `App\Modules\CRM\Listeners\LogProjectStageChanged`.
 * Registrado en `App\Providers\AppServiceProvider::boot()` vía `Event::listen()`, despachado
 * síncronamente dentro del mismo request que aprueba la cotización.
 *
 * Blocker 1 de Senior Review (2026-07-22): este listener corre SÍNCRONO dentro del request HTTP
 * de `PATCH .../pipeline/{id}/stage` — cualquier excepción sin capturar acá (FK rota, columna
 * desbordada, Redis caído al encolar el email de notificación, etc.) se propagaba hasta el
 * controller y el usuario que aprobaba una venta recibía un 500, violando la decisión explícita
 * de Luis: "un fallo del lado de Bodega no puede bloquear ni romper la aprobación de una venta".
 * `try/catch` + `Log::error()` acá es la mitigación correcta — nunca dejar que la excepción
 * escape, con contexto suficiente (`pipeline_card_id`, mensaje) para crear el pedido a mano si
 * hace falta. Convertir el listener en `ShouldQueue` fue considerado y descartado: cambiaría
 * "aparece automáticamente" (REQ-308) a "aparece con demora", que no es lo que pide el criterio
 * de aceptación.
 */
class CreateOrderFromApprovedQuote
{
    public function __construct(private readonly OrderService $orderService) {}

    public function handle(QuoteApproved $event): void
    {
        try {
            $this->orderService->createFromApprovedQuote($event->pipelineCardId, $event->ownerId);
        } catch (Throwable $e) {
            Log::error('bodega.order.creation-failed', [
                'pipeline_card_id' => $event->pipelineCardId,
                'sales_project_id' => $event->salesProjectId,
                'owner_id'         => $event->ownerId,
                'tenant'           => $event->tenant,
                'exception'        => $e->getMessage(),
                'exception_class'  => $e::class,
            ]);
        }
    }
}
