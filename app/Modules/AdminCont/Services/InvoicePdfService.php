<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContCompanyProfile;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Batch 4 de Facturación (SCRUM-524→528, REQ-449) — primer documento fiscal real e imprimible de
 * Admin&Cont (hasta acá, la "vista previa" de Batch 3 solo vivía en pantalla). RN1: datos de
 * empresa (`AdminContFiscalSettings`) + cliente + tabla de ítems + subtotal/ITBMS/total + bloque de
 * pago. RN2: si se aplicó saldo a favor, "Total factura" Y "Total a pagar" visibles. RN3: marca de
 * agua "ANULADA" cuando `es_anulada` — implementada con `position: fixed` cubriendo la página
 * completa en una sola pieza (no depende de la altura de ningún contenido, así que no repite el
 * gotcha real de dompdf con `position:absolute` + `min-height` adivinado que sí desbordó 2 veces
 * en otras plantillas de este proyecto, ver memoria `feedback_dompdf_absolute_position_fixed_
 * minheight_overlap`). Logo vía `PdfLogo::dataUri()` — dompdf no renderiza `<svg>` inline.
 */
final class InvoicePdfService
{
    /** @param array<string, mixed> $detail Salida de `InvoiceService::detail()`. */
    public function generate(array $detail): PdfDocument
    {
        return Pdf::loadView('admin-cont.invoice-pdf', $this->viewData($detail))
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
