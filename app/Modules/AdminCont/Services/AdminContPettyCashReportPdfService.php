<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * REQ-539 RN3 — "Descargar" de un reporte finalizado. Mismo patrón (dompdf + Blade) que
 * `DailyCashCountSummaryPdfService`/`CreditNotePdfService`. Sin logo de empresa a propósito
 * (simplificación de alcance de este batch — ningún AC de REQ-535→540 lo exige; si Luis lo pide,
 * es el mismo patrón `<img data:...base64>` que ya usan los otros PDF del módulo).
 */
final class AdminContPettyCashReportPdfService
{
    /** @param array<string, mixed> $data resultado de AdminContPettyCashService::reportDetail() */
    public function toPdf(array $data): string
    {
        return Pdf::loadView('admin-cont.petty-cash-report-pdf', [...$data, 'generatedAt' => now()])
            ->setPaper('letter')
            ->output();
    }
}
