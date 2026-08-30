<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Batch 13 (REQ-496) — documento fiscal formal de una Nota de Crédito, listo para imprimir. Mismo
 * patrón que `InvoicePdfService`/`PaymentReceiptPdfService` (dompdf, logo vía `PdfLogo::dataUri()`
 * — dompdf no renderiza `<svg>` inline). RN3: el estado real de la nota siempre visible (badge de
 * estado en la plantilla) — una nota rechazada nunca aparenta estar aplicada.
 */
final class CreditNotePdfService
{
    /** @param array<string, mixed> $detail Salida de `CreditNoteService::show()`. */
    public function generate(array $detail): PdfDocument
    {
        return Pdf::loadView('admin-cont.credit-note-pdf', $this->viewData($detail))
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
