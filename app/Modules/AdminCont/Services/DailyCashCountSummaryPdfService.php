<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Support\PdfFooterText;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Batch 19 (REQ-529) — "Descargar resumen", documento formal imprimible de un arqueo (activo o
 * cerrado).
 *
 * SCRUM-878 — rediseñado para seguir el mockup en el CUERPO (bloque Resumen con líneas separadas,
 * sección Conciliación, una sola tabla de movimientos con columnas Concepto/Medio/Observaciones/
 * Monto, recuadro de totales al final) y el ESTÁNDAR del sistema en encabezado/pie (logo
 * Atlantic + `PdfFooterText::forFiscal()` + numeración de página), igual que
 * `AccountStatementPdfService`/`PaymentReceiptPdfService` — nunca la barra oscura ni el
 * "Documento generado por AtlanticERP" que aparecen en el mockup.
 */
final class DailyCashCountSummaryPdfService
{
    /** @param array<string, mixed> $data resultado de DailyCashCountService::active()/detail() */
    public function toPdf(array $data): string
    {
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('admin-cont.daily-cash-count-summary-pdf', $this->viewData($data))
            ->setPaper('letter')
            ->output();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function viewData(array $data): array
    {
        $fiscal = AdminContFiscalSettings::current();

        return [
            ...$data,
            'generatedAt'       => now(),
            'logoDataUri'       => PdfLogo::dataUri(),
            // PM Review SCRUM-878 — el nombre/RUC/dirección de la empresa ya no se repiten en el
            // cuerpo (el logo del encabezado alcanza, mismo estándar que account-statement-pdf);
            // solo el pie de página los sigue mostrando, vía footerCompanyText.
            'footerCompanyText' => PdfFooterText::forFiscal($fiscal),
            'metodoLabels'      => PaymentMethods::labels(),
        ];
    }
}
