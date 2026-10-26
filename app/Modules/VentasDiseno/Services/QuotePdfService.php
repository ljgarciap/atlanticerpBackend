<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Modules\AdminCont\Support\PdfFooterText;
use App\Modules\AdminCont\Support\QuotePaymentInstructions;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * SCRUM-766 — plantilla única de Cotización (Carta, con paginación real). Reemplaza el enfoque
 * anterior (`QuoteDocument.tsx` + `window.print()`, sin generador de PDF real) — decisión de Luis
 * 2026-08-16: las vistas INTERNAS (los 6 puntos de acceso del ticket) deben verse exactamente
 * iguales entre sí y contra lo descargado, generadas por un motor de PDF real; la política de
 * ocultar precio/descuento/total/observaciones/pago/condiciones/totales/firma en la vista externa
 * (SCRUM-140) se mantiene sin cambios — `external` solo gatea visibilidad de campos dentro de esta
 * MISMA plantilla, nunca hay una plantilla separada (regla explícita del ticket, sección 25).
 *
 * `viewData()` separado de `generate()` (mismo patrón que `ServiceQuotePdfService`) para poder
 * testear la forma exacta de los datos sin parsear bytes de PDF.
 *
 * `isRemoteEnabled` — la columna FOTO necesita cargar `catalog_products.photo_url` (URL pública
 * ya usada tal cual por `CatalogPdfService`, columna simple sin disco S3 propio — ver ese archivo).
 * `isPhpEnabled` — necesario para el mecanismo de paginación real (`page_text()`, ver el bloque
 * `<script type="text/php">` en `quote-pdf.blade.php`) — es el único mecanismo de dompdf con
 * soporte real para "Página X de Y"; nunca se evalúa contenido de usuario como PHP, la vista es
 * 100% generada por este servicio, todos los valores interpolados van escapados por Blade (`{{ }}`).
 *
 * `logoDataUri` — rebote de Gerencia 2026-08-20: el encabezado seguía con el SVG inline original
 * (dompdf en este entorno no lo renderiza, ver docblock de `PdfLogo`) — ese gap ya se había
 * diagnosticado y arreglado en los 3 PDFs de Servicios (SCRUM-781) pero nunca se retrofiteó acá.
 */
final class QuotePdfService
{
    public function __construct(private readonly QuotePdfLayoutEstimator $layoutEstimator)
    {
    }

    /** @param array<string, mixed> $detail Salida de `QuoteController::formatDetail()`. */
    public function generate(array $detail, bool $external): PdfDocument
    {
        return Pdf::setOption('isRemoteEnabled', true)
            ->setOption('isPhpEnabled', true)
            ->loadView('ventas-diseno.quote-pdf', $this->viewData($detail, $external))
            ->setPaper('letter');
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function viewData(array $detail, bool $external): array
    {
        // SCRUM-801 (3er rebote) — bloque final (Observaciones→Firma) pegado al pie de página y
        // sin partirse entre páginas. Ver docblock de QuotePdfLayoutEstimator para el diseño.
        $paymentLines = QuotePaymentInstructions::lines();
        $bottomBlockLayout = $this->layoutEstimator->estimate($detail + ['payment_lines' => $paymentLines], $external);

        return [
            'quote'              => $detail,
            'paymentLines'       => $paymentLines,
            'footerCompanyText'  => PdfFooterText::forCurrentCompany(),
            'external'           => $external,
            'signed'             => $detail['document_status'] === 'approved',
            'signerName'         => $detail['contacts'][0]['name'] ?? $detail['sub_client']['business_name'] ?? '',
            'generatedAt'        => now(),
            'logoDataUri'        => PdfLogo::dataUri(),
            'bottomBlockSpacerPt' => $bottomBlockLayout['spacerPt'],
            'bottomBlockForceNewPage' => $bottomBlockLayout['forceNewPage'],
        ];
    }
}
