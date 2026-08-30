<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Batch 19 (REQ-529) — "Descargar resumen", documento formal imprimible de un arqueo (activo o
 * cerrado). Mismo patrón (dompdf + Blade) que `CashPositionExportService`/`PaymentReceiptPdfService`.
 * RN1: número+fecha, totales, observación general, detalle de retenciones+estado de constancia,
 * estado de aprobación de Gerencia con quién y cuándo.
 */
final class DailyCashCountSummaryPdfService
{
    /** @param array<string, mixed> $data resultado de DailyCashCountService::active()/detail() */
    public function toPdf(array $data): string
    {
        return Pdf::loadView('admin-cont.daily-cash-count-summary-pdf', [...$data, 'generatedAt' => now()])
            ->setPaper('letter')
            ->output();
    }
}
