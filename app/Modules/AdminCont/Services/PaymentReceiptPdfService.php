<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Support\PdfFooterText;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Batch 7 de Cobros (SCRUM-550, REQ-473) — recibo formal de un cobro, listo para imprimir o
 * entregar al cliente. Mismo patrón que `InvoicePdfService` (datos de empresa vía
 * `AdminContFiscalSettings`/`AdminContCompanyProfile`, logo vía `PdfLogo::dataUri()` porque dompdf
 * no renderiza `<svg>` inline). RN2 — el recibo de un cobro "Esperando confirmación" muestra ese
 * estado de forma visible, nunca "Confirmado".
 */
final class PaymentReceiptPdfService
{
    /** @param array<string, mixed> $detail Salida de `AdminContPaymentController::presentDetail()`. */
    public function generate(array $detail): PdfDocument
    {
        // Rebote de Pre-QA (SCRUM-858) — `isPhpEnabled` necesario para que el pie de página use
        // `page_text()` (ver `<script type="text/php">` en `payment-receipt-pdf.blade.php`);
        // dompdf trae `enable_php => false` por defecto, así que sin este flag el bloque se
        // ignora en silencio y el PDF sale sin footer, mismo patrón que `QuotePdfService` y
        // `OrderDocumentService::generateGuiaEntrega()`. Nunca se evalúa contenido de usuario
        // como PHP — la vista es 100% generada por este servicio, valores interpolados vía Blade.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('admin-cont.payment-receipt-pdf', $this->viewData($detail))
            ->setPaper('letter');
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    public function viewData(array $detail): array
    {
        $fiscal  = AdminContFiscalSettings::current();
        $company = AdminContCompanyProfile::current();

        return [
            'detail'            => $detail,
            'fiscal'            => $fiscal,
            'moneda'            => $company->moneda,
            'logoDataUri'       => PdfLogo::dataUri(),
            // Rebote de QA (SCRUM-858, qa.qa 2026-09-08) — el footer viejo tenía un texto
            // propio hardcodeado ("Atlantic - Panama - Documento generado por AtlanticERP"), en vez
            // de leer los datos reales de la empresa como ya hacía quote-pdf.blade.php. Ver
            // PdfFooterText, compartido con AccountStatementPdfService (SCRUM-857).
            'footerCompanyText' => PdfFooterText::forFiscal($fiscal),
            // AdminContFiscalSettings está vacío en dev/test/prod (ver docblock de PdfFooterText)
            // — sin esto el nombre de empresa del encabezado salía en blanco.
            'companyName'       => PdfFooterText::companyName($fiscal),
        ];
    }
}
