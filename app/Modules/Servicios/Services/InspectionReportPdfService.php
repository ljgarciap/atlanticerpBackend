<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * REQ-244 (Batch 9) — "Ver informe completado y Ver/Imprimir". Dos documentos:
 * `generate()` — informe lleno (findings ya guardados en el registro), disponible solo cuando el
 * informe existe. `generateBlank()` — plantilla en blanco por tipo/subtipo del ticket, disponible
 * sin informe todavía; reusa `InspectionReportService::fieldsForTicket()` (que a su vez usa
 * `STATIC_FIELDS_BY_TIPO`) para el catálogo de campos — nunca duplica esas etiquetas acá. Mismo
 * patrón dompdf que `TicketPdfService`/`CatalogPdfService`.
 *
 * Batch 10 (REQ-254 RN4) — cuando el informe se completó desde el Informe móvil,
 * `firma_cliente_imagen` tiene una firma gráfica real que este documento debe mostrar (no solo el
 * nombre en texto, que es lo único que existía hasta Batch 9). Se embebe como data URI en vez de
 * dejar que dompdf resuelva la URL presignada de S3 en tiempo de render — más confiable (sin
 * depender de que la red saliente del contenedor alcance S3 en ese momento) y evita filtrar una
 * URL firmada dentro del HTML intermedio que dompdf procesa.
 */
final class InspectionReportPdfService
{
    private const DISK = 's3-private';
    /** Mismas etiquetas que `TicketPdfService::TIPO_LABELS` — cada PdfService las duplica, no hay
     *  infraestructura de i18n de dominio en el backend (mismo criterio ya usado en el módulo). */
    private const TIPO_LABELS = [
        Ticket::TIPO_INSTALLATION => 'Instalación',
        Ticket::TIPO_WARRANTY     => 'Garantía',
        Ticket::TIPO_CLAIM        => 'Reclamo',
        Ticket::TIPO_RETROFIT     => 'Retrofit',
    ];

    private const SUBTIPO_LABELS = [
        Ticket::SUBTIPO_INSTALLATION           => 'Instalación',
        Ticket::SUBTIPO_INSPECTION             => 'Inspección',
        Ticket::SUBTIPO_WARRANTY_GENERIC       => 'Garantía general',
        Ticket::SUBTIPO_REPLACEMENT_INSPECTION => 'Inspección de reemplazo',
    ];

    public function __construct(private readonly InspectionReportService $reports) {}

    public function generate(InspectionReport $report, Ticket $ticket): PdfDocument
    {
        // SCRUM-891/893 — isPhpEnabled requerido para el pie de página real (page_text(), ver
        // `pdf.partials.footer`), mismo mecanismo que ServiceQuotePdfService/QuotePdfService.
        return Pdf::setOption('isPhpEnabled', true)->loadView('servicios.inspection-report-pdf', [
            'ticket'                   => $ticket,
            'report'                   => $report,
            'tipoLabel'                => self::TIPO_LABELS[$ticket->tipo] ?? $ticket->tipo,
            'subtipoLabel'             => $ticket->subtipo === null ? null : (self::SUBTIPO_LABELS[$ticket->subtipo] ?? $ticket->subtipo),
            'generatedAt'              => now(),
            'firmaClienteImagenDataUri' => $this->signatureImageDataUri($report),
            'logoDataUri'              => PdfLogo::dataUri(),
        ])->setPaper('letter');
    }

    private function signatureImageDataUri(InspectionReport $report): ?string
    {
        if ($report->firma_cliente_imagen === null) {
            return null;
        }

        try {
            $bytes = Storage::disk(self::DISK)->get($report->firma_cliente_imagen);
        } catch (\Throwable $e) {
            Log::error('inspection_report_pdf_service.signature_image_read_failed', [
                'report_id' => $report->id,
                'key'       => $report->firma_cliente_imagen,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }

        if ($bytes === null) {
            return null;
        }

        $ext  = strtolower(pathinfo($report->firma_cliente_imagen, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp'        => 'image/webp',
            default        => 'image/png',
        };

        return "data:{$mime};base64,".base64_encode($bytes);
    }

    /** Plantilla en blanco — solo etiquetas del catálogo, sin valores, para llenar a mano en campo. */
    public function generateBlank(Ticket $ticket): PdfDocument
    {
        return Pdf::setOption('isPhpEnabled', true)->loadView('servicios.inspection-report-pdf-blank', [
            'ticket'       => $ticket,
            'fields'       => $this->reports->fieldsForTicket($ticket),
            'tipoLabel'    => self::TIPO_LABELS[$ticket->tipo] ?? $ticket->tipo,
            'subtipoLabel' => $ticket->subtipo === null ? null : (self::SUBTIPO_LABELS[$ticket->subtipo] ?? $ticket->subtipo),
            'generatedAt'  => now(),
            'logoDataUri'  => PdfLogo::dataUri(),
        ])->setPaper('letter');
    }
}
