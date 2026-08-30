<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * REQ-279 — documento formal "Hoja de Reclamo" (Ver/Imprimir), 4 secciones calcadas del Word real
 * de la empresa (RN1 de SCRUM-349): 1) datos del reclamante, 2) producto(s) reclamado(s),
 * 3) descripción del problema y diagnóstico, 4) firma del responsable. Solo se emite con la hoja
 * ya `completed` (RN2) — el Controller ya lo garantiza antes de llamar acá. Mismo patrón dompdf
 * que `InspectionReportPdfService`/`TicketPdfService`.
 */
final class ClaimSheetPdfService
{
    private const DIAGNOSTICO_LABELS = [
        ClaimSheet::DIAGNOSTICO_DEFECTUOSO           => 'Defecto de fábrica',
        ClaimSheet::DIAGNOSTICO_NO_PROCEDE            => 'No procede',
        ClaimSheet::DIAGNOSTICO_USO_INADECUADO        => 'Uso inadecuado',
        ClaimSheet::DIAGNOSTICO_PENDIENTE_EVALUACION  => 'Pendiente de evaluación',
    ];

    /**
     * @param array<string, mixed> $detail Salida de `TicketService::detail()`.
     */
    public function generate(ClaimSheet $sheet, Ticket $ticket, array $detail): PdfDocument
    {
        return Pdf::loadView('servicios.claim-sheet-pdf', [
            'ticket'           => $ticket,
            'sheet'            => $sheet,
            'detail'           => $detail,
            'diagnosticoLabel' => self::DIAGNOSTICO_LABELS[$sheet->diagnostico] ?? $sheet->diagnostico,
            'generatedAt'      => now(),
            'logoDataUri'      => PdfLogo::dataUri(),
        ])->setPaper('letter');
    }
}
