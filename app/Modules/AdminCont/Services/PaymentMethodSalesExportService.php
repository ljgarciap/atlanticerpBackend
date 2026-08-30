<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPayment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 23 Grupo 3 (SCRUM-669, REQ-592) — Excel de "Ventas por medio de pago". Mismo patrón que
 * `ClientCollectionReportExportService` (PhpSpreadsheet, bytes vía archivo temporal), hoja
 * "Pendientes" condicional (RN de REQ-592, mismo criterio que 4M1/4M2).
 */
final class PaymentMethodSalesExportService
{
    /**
     * @param array{resumen: array<string, mixed>, filas: list<array<string, mixed>>, pendientes: list<array<string, mixed>>} $report
     */
    public function toExcel(array $report): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ventas por medio de pago');

        $metodoHeaders = array_map(fn (string $m) => AdminContPayment::METODO_LABELS[$m], AdminContPayment::METODOS);
        $headers       = ['Fecha', 'Cliente', 'N° Documento', 'Base Imponible', 'ITBMS', 'Total', ...$metodoHeaders];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);

        $rows = array_map(function (array $f): array {
            $row = [
                $f['fecha'], $f['cliente'], $f['documento'],
                number_format((float) $f['base_imponible'], 2), number_format((float) $f['itbms'], 2), number_format((float) $f['total'], 2),
            ];
            foreach (AdminContPayment::METODOS as $m) {
                $row[] = number_format((float) $f[$m], 2);
            }

            return $row;
        }, $report['filas']);

        $totalRow = ['Total del período', '', '', number_format((float) $report['resumen']['base_imponible'], 2), number_format((float) $report['resumen']['itbms'], 2), number_format((float) $report['resumen']['total'], 2)];
        foreach (AdminContPayment::METODOS as $m) {
            $totalRow[] = number_format((float) array_sum(array_column($report['filas'], $m)), 2);
        }
        $rows[] = $totalRow;

        $sheet->fromArray($rows, null, 'A2');
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($report['pendientes'] !== []) {
            $pendientesSheet = $spreadsheet->createSheet();
            $pendientesSheet->setTitle('Pendientes');
            $pendientesSheet->fromArray(['Fecha', 'N° Documento', 'Cliente', 'Monto'], null, 'A1');
            $pendientesSheet->getStyle('A1:D1')->getFont()->setBold(true);
            $pendientesSheet->fromArray(array_map(fn (array $p): array => [
                $p['fecha'], $p['documento'], $p['cliente'], number_format((float) $p['monto'], 2),
            ], $report['pendientes']), null, 'A2');
            foreach (range(1, 4) as $col) {
                $pendientesSheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'payment_method_sales_');
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
