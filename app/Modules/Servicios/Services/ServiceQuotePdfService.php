<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\ServiceQuoteItem;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Fase 4 — Servicios, Batch 12 (REQ-235, SCRUM-298) — documento formal "Ver/Imprimir" de una
 * Cotización de Servicio. Mismo patrón dompdf que `ClaimSheetPdfService`/`InspectionReportPdfService`.
 *
 * Dos reglas duras de la spec, sin excepción de permiso ni de estado:
 * 1. Los ítems tipo Subcontratado NUNCA muestran costo/margen en este documento — de hecho,
 *    NINGÚN ítem muestra costo/margen acá (ver `formatItems()`): es un documento de cara al
 *    cliente, el desglose de costo real/margen (`servicios.quotes.view_cost_breakdown`) es
 *    exclusivamente una herramienta interna del formulario (`ServiceQuoteController::formatItem()`)
 *    — replicar ese gate acá sería la superficie de bypass exacta que Senior Review ya cerró una
 *    vez en el formulario (ver docblock de `formatItem()`), así que la forma más simple y segura de
 *    no reabrirla es no exponer esas columnas en absoluto en el documento impreso.
 * 2. Firma simulada ("✓ Firmado" + nombre del cliente) SOLO si `estado=approved` — cualquier otro
 *    estado muestra la línea de firma vacía, igual que el resto de los documentos del módulo.
 *
 * `conditions` — si la cotización ya fue enviada (`conditions` congelado, ver
 * `ServiceQuoteService::send()`) se usa ese texto; si todavía está en Borrador (nunca se congeló)
 * se usa el valor VIGENTE de `ServiciosSettingsService` como preview — mismo criterio que
 * `ServiceQuoteController::detail()`, nunca hardcodeado en esta vista.
 */
final class ServiceQuotePdfService
{
    public function __construct(private readonly ServiciosSettingsService $settings) {}

    /** @param array<string, mixed> $detail Salida de `TicketService::detail()`. */
    public function generate(ServiceQuote $quote, Ticket $ticket, array $detail): PdfDocument
    {
        return Pdf::loadView('servicios.service-quote-pdf', $this->viewData($quote, $ticket, $detail))
            ->setPaper('letter');
    }

    /**
     * Extraído de `generate()` para poder testear la regla "ningún ítem expone costo/margen en
     * este documento" (ver docblock de la clase) sin depender de parsear bytes de un PDF renderizado
     * — el mismo array que se le pasa a dompdf es lo que un test de Feature puede inspeccionar
     * directamente.
     *
     * @param array<string, mixed> $detail Salida de `TicketService::detail()`.
     * @return array<string, mixed>
     */
    public function viewData(ServiceQuote $quote, Ticket $ticket, array $detail): array
    {
        return [
            'ticket'      => $ticket,
            'detail'      => $detail,
            'quote'       => $quote,
            'items'       => $this->formatItems($quote),
            'conditions'  => $quote->conditions ?? $this->settings->conditionsCotizacionServicios(),
            'firmada'     => $quote->estado === ServiceQuote::ESTADO_APPROVED,
            'generatedAt' => now(),
            'logoDataUri' => PdfLogo::dataUri(),
        ];
    }

    /** @return array<int, array{description: string, quantity: float, unit_price: float, subtotal: float}> */
    private function formatItems(ServiceQuote $quote): array
    {
        return $quote->items->map(fn (ServiceQuoteItem $item): array => [
            'description' => $item->description,
            'quantity'    => $item->quantity,
            'unit_price'  => $item->unit_price,
            'subtotal'    => $item->subtotal,
        ])->values()->all();
    }
}
