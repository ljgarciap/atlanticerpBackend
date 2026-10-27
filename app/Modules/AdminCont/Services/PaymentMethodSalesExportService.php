<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Models\AdminContPayment;
use App\Modules\AdminCont\Support\ExcelMoney;
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

        // SCRUM-880 — orden propio del reporte (Yappy/Link antes de Tarjeta/Depósito), no el de
        // AdminContPayment::METODOS.
        $metodoHeaders = array_map(fn (string $m) => AdminContPayment::METODO_LABELS[$m], PaymentMethodSalesReportService::COLUMNAS_METODO_ORDEN);
        $headers       = ['Fecha', 'Cliente', 'N° Documento', 'Base Imponible', \App\Shared\Multitenancy\TenantRegion::taxLabel(), 'Total', ...$metodoHeaders];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);

        $rows = array_map(function (array $f): array {
            $row = [
                $f['fecha'], $f['cliente'], $f['documento'],
                ExcelMoney::value($f['base_imponible']), ExcelMoney::value($f['itbms']), ExcelMoney::value($f['total']),
            ];
            foreach (PaymentMethodSalesReportService::COLUMNAS_METODO_ORDEN as $m) {
                $row[] = ExcelMoney::value($f[$m]);
            }

            return $row;
        }, $report['filas']);

        $totalRow = ['Total del período', null, null, ExcelMoney::value($report['resumen']['base_imponible']), ExcelMoney::value($report['resumen']['itbms']), ExcelMoney::value($report['resumen']['total'])];
        foreach (PaymentMethodSalesReportService::COLUMNAS_METODO_ORDEN as $m) {
            $totalRow[] = ExcelMoney::value(array_sum(array_column($report['filas'], $m)));
        }
        $rows[] = $totalRow;

        // strictNullComparison: con la comparación laxa por defecto `0.0 == null` y PhpSpreadsheet omitiría
        // las celdas en cero (saldrían vacías en vez de $0.00) — solo `null` cuenta como celda vacía.
        $sheet->fromArray($rows, null, 'A2', strictNullComparison: true);

        // SCRUM-935 — Base Imponible, ITBMS, Total y todas las columnas de medio de pago son moneda
        // (todo desde la columna D en adelante).
        ExcelMoney::apply($sheet, 'D2:'.Coordinate::stringFromColumnIndex(count($headers)).(count($rows) + 1));
        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($report['pendientes'] !== []) {
            $pendientesSheet = $spreadsheet->createSheet();
            $pendientesSheet->setTitle('Pendientes');
            $pendientesSheet->fromArray(['Fecha', 'N° Documento', 'Cliente', 'Monto'], null, 'A1');
            $pendientesSheet->getStyle('A1:D1')->getFont()->setBold(true);
            $pendientesSheet->fromArray(array_map(fn (array $p): array => [
                $p['fecha'], $p['documento'], $p['cliente'], ExcelMoney::value($p['monto']),
            ], $report['pendientes']), null, 'A2', strictNullComparison: true);
            ExcelMoney::apply($pendientesSheet, 'D2:D'.(count($report['pendientes']) + 1));
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
