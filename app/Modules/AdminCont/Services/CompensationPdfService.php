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
 * SCRUM-1168 F3 — documento de la compensación (cruce) con su folio CMP-XXX: de qué cobros, abonos o notas salió el dinero y a qué facturas se
 * aplicó. Mismo encabezado y pie que los recibos de cobro y de abono.
 */
final class CompensationPdfService
{
    /** @param array<string, mixed> $detail Salida de `CompensationService::presentar()`. */
    public function generate(array $detail): PdfDocument
    {
        $fiscal  = AdminContFiscalSettings::current();
        $company = AdminContCompanyProfile::current();

        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('admin-cont.compensation-pdf', [
                'detail' => $detail, 'fiscal' => $fiscal, 'moneda' => $company->moneda, 'logoDataUri' => PdfLogo::dataUri(),
                'footerCompanyText' => PdfFooterText::forFiscal($fiscal),
            ])
            ->setPaper('letter');
    }
}
