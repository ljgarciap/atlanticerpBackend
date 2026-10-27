<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Support\ExcelMoney;
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

        $headers = ['Fecha', 'RUC', 'Cliente', 'Tipo', 'Motivo', 'N° Documento', 'Base Imponible', '%', 'Cuota '.\App\Shared\Multitenancy\TenantRegion::taxLabel(), 'Total', 'Vendedor'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);

        $rows = array_map(fn (array $d): array => [
            $d['fecha'], (string) ($d['ruc'] ?? '—'), $d['cliente'], $d['tipo'], (string) ($d['motivo'] ?? '—'),
            $d['documento'], ExcelMoney::value($d['base_imponible']), number_format((float) $d['porcentaje'], 1).'%',
            ExcelMoney::value($d['itbms']), ExcelMoney::value($d['total']), (string) ($d['vendedor'] ?? '—'),
        ], $report['documentos']);

        $resumen  = $report['resumen'];
        $rows[] = ['Total del período', null, null, null, null, null, ExcelMoney::value($resumen['base_imponible']), null, ExcelMoney::value($resumen['itbms']), ExcelMoney::value($resumen['total']), null];

        // strictNullComparison: con la comparación laxa por defecto `0.0 == null` y PhpSpreadsheet omitiría
        // las celdas en cero (saldrían vacías en vez de $0.00) — solo `null` cuenta como celda vacía.
        $sheet->fromArray($rows, null, 'A2', strictNullComparison: true);

        // SCRUM-935 — Base Imponible (G), Cuota ITBMS (I) y Total (J) son moneda; "%" (H) sigue texto.
        $lastRow = count($rows) + 1;
        ExcelMoney::apply($sheet, "G2:G{$lastRow}");
        ExcelMoney::apply($sheet, "I2:J{$lastRow}");
        foreach (range(1, 11) as $col) {
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
