<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
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
        return Pdf::loadView('admin-cont.payment-receipt-pdf', $this->viewData($detail))
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
            'detail'      => $detail,
            'fiscal'      => $fiscal,
            'moneda'      => $company->moneda,
            'logoDataUri' => PdfLogo::dataUri(),
        ];
    }
}
