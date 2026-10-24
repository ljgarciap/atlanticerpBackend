<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Support\PdfFooterText;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * REQ-539 RN3 — "Descargar" de un reporte finalizado. Mismo patrón (dompdf + Blade) que
 * `DailyCashCountSummaryPdfService`/`CreditNotePdfService`.
 *
 * SCRUM-890/893 — el logo/encabezado/pie reales que el resto de los descargables ya tiene se
 * agregan acá (la simplificación de alcance original de REQ-535→540 quedó reemplazada por el
 * estándar de SCRUM-853/893: todo documento descargable lleva marca+footer real).
 */
final class AdminContPettyCashReportPdfService
{
    /** @param array<string, mixed> $data resultado de AdminContPettyCashService::reportDetail() */
    public function toPdf(array $data): string
    {
        $fiscal = AdminContFiscalSettings::current();

        // isPhpEnabled requerido para el pie de página real (page_text(), ver
        // `pdf.partials.footer`), mismo mecanismo que ServiceQuotePdfService/QuotePdfService.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('admin-cont.petty-cash-report-pdf', [
                ...$data,
                'generatedAt'       => now(),
                'logoDataUri'       => PdfLogo::dataUri(),
                'footerCompanyText' => PdfFooterText::forFiscal($fiscal),
            ])
            ->setPaper('letter')
            ->output();
    }
}
