<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\Ticket;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * REQ-228 — PDF de "Ver/Imprimir" del ticket. RN1: mismo contenido que el modal de detalle
 * (REQ-224) — recibe el array ya armado por `TicketService::detail()` en vez de recalcularlo,
 * para no duplicar la lógica de derivación (Cliente Master/Subcliente, horario, etc.). Mismo
 * patrón que `CatalogPdfService` (dompdf, `Pdf::loadView()`).
 *
 * Logo embebido (rebote SCRUM-781 2026-08-24) — este PDF (botón "Ver/Imprimir" del detalle del
 * ticket, distinto de la Hoja de Reclamo/Informe/Cotización) se quedó fuera del fix de logo de
 * `dbefecc` (solo tocó esos 3 servicios), aunque comparte la misma marca "ATLANTIC" en texto
 * plano — mismo `PdfLogo::dataUri()` ya usado ahí.
 */
final class TicketPdfService
{
    /** Mismas etiquetas en español usadas en el frontend (`i18n/locales/es/servicios.json`,
     *  `tickets.tipo.*`) — el backend no tiene infraestructura de i18n de dominio, mismo patrón
     *  que `CatalogPdfService::SPEC_LABELS`. */
    private const TIPO_LABELS = [
        Ticket::TIPO_INSTALLATION => 'Instalación',
        Ticket::TIPO_WARRANTY     => 'Garantía',
        Ticket::TIPO_CLAIM        => 'Reclamo',
        Ticket::TIPO_RETROFIT     => 'Retrofit',
    ];

    /** Mismas etiquetas que `i18n/locales/es/servicios.json`, `tickets.subtypes.*`. */
    private const SUBTIPO_LABELS = [
        Ticket::SUBTIPO_INSTALLATION          => 'Instalación',
        Ticket::SUBTIPO_INSPECTION            => 'Inspección',
        Ticket::SUBTIPO_WARRANTY_GENERIC      => 'Garantía general',
        Ticket::SUBTIPO_REPLACEMENT_INSPECTION => 'Inspección de reemplazo',
    ];

    /**
     * @param array<string, mixed> $detail Salida de `TicketService::detail()`.
     */
    public function generate(Ticket $ticket, array $detail): PdfDocument
    {
        // SCRUM-924 (rebote Daniela 2026-09-20) — isPhpEnabled requerido para el pie de página
        // real (page_text(), ver `ticket-pdf.blade.php`), mismo patrón que el resto de servicios
        // de PDF del proyecto (SCRUM-893). Sin esto dompdf ignora el bloque <script type="text/php">
        // en silencio, sin error — el pie seguía siendo contenido normal del documento.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('servicios.ticket-pdf', [
                'ticket'        => $ticket,
                'tipoLabel'     => self::TIPO_LABELS[$ticket->tipo] ?? $ticket->tipo,
                'subtipoLabel'  => $ticket->subtipo === null ? null : (self::SUBTIPO_LABELS[$ticket->subtipo] ?? $ticket->subtipo),
                'detail'        => $detail,
                'horario'       => $this->formatHorario($ticket),
                'fechaServicio' => $ticket->scheduled_at?->format('d/m/Y') ?? 'Sin agendar',
                'generatedAt'   => now(),
                'logoDataUri'   => PdfLogo::dataUri(),
            ])
            ->setPaper('letter');
    }

    private function formatHorario(Ticket $ticket): string
    {
        if ($ticket->scheduled_at === null) {
            return 'Sin agendar';
        }

        if ($ticket->scheduled_ends_at === null) {
            return $ticket->scheduled_at->format('h:i A');
        }

        return $ticket->scheduled_at->format('h:i A').' – '.$ticket->scheduled_ends_at->format('h:i A');
    }
}
