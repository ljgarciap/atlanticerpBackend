<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 23 Grupo 3 (SCRUM-664, REQ-587) — Excel del Libro de Facturas. Mismo patrón que
 * `ClientCollectionReportExportService`/`InvoiceExportService` (PhpSpreadsheet, bytes vía archivo
 * temporal). Una sola hoja — este reporte no tiene sección de "Pendientes".
 */
final class InvoiceBookExportService
{
    /**
     * @param array{resumen: array<string, mixed>, documentos: list<array<string, mixed>>} $report
     */
    public function toExcel(array $report): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Libro de facturas');

        $headers = ['Fecha', 'RUC', 'Cliente', 'Tipo', 'Motivo', 'N° Documento', 'Base Imponible', '%', 'Cuota ITBMS', 'Total'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        $rows = array_map(fn (array $d): array => [
            $d['fecha'], (string) ($d['ruc'] ?? '—'), $d['cliente'], $d['tipo'], (string) ($d['motivo'] ?? '—'),
            $d['documento'], number_format((float) $d['base_imponible'], 2), number_format((float) $d['porcentaje'], 1).'%',
            number_format((float) $d['itbms'], 2), number_format((float) $d['total'], 2),
        ], $report['documentos']);

        $resumen  = $report['resumen'];
        $rows[] = ['Total del período', '', '', '', '', '', number_format((float) $resumen['base_imponible'], 2), '', number_format((float) $resumen['itbms'], 2), number_format((float) $resumen['total'], 2)];

        $sheet->fromArray($rows, null, 'A2');
        foreach (range(1, 10) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'invoice_book_');
        if ($tmpPath === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal para el export a Excel.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tmpPath);
            $bytes = file_get_contents($tmpPath);

            if ($bytes === false) {
                throw new \RuntimeException('No se pudo leer el archivo Excel generado.');
            }

            return $bytes;
        } finally {
            @unlink($tmpPath);
        }
    }
}
