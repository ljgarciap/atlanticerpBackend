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
 * SCRUM-955 punto 2 — recibo formal de un abono, mismo concepto/comportamiento que
 * `PaymentReceiptPdfService` (REQ-473) para Cobros, pero para Abonos: un solo monto (no una lista
 * de facturas aplicadas), folio propio ABN-XXX (`AdminContAbonoFolioService`, secuencia
 * independiente de REC-XXX).
 */
final class AbonoReceiptPdfService
{
    /** @param array<string, mixed> $detail Salida de `AdminContAbonoController::presentReceiptDetail()`. */
    public function generate(array $detail): PdfDocument
    {
        // Mismo motivo que PaymentReceiptPdfService — dompdf trae `enable_php => false` por
        // defecto, y el pie de página usa `page_text()` vía `<script type="text/php">`.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('admin-cont.abono-receipt-pdf', $this->viewData($detail))
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
            'footerCompanyText' => PdfFooterText::forFiscal($fiscal),
            'companyName'       => PdfFooterText::companyName($fiscal),
        ];
    }
}
