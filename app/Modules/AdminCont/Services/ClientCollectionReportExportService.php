<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Support\ExcelMoney;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 23 Grupo 2 (SCRUM-655/660, REQ-578/583) — Excel de "Reporte mensual por cliente" (4M1) y
 * "Mensual por cliente — acumulado" (4M2). Mismo patrón que `InvoiceExportService::toExcel()`
 * (PhpSpreadsheet, bytes vía archivo temporal). Hoja "Pendientes" solo se agrega si hay al menos
 * 1 factura pendiente (RN3 REQ-578/583) — primer uso de una 2da hoja condicional en el módulo.
 */
final class ClientCollectionReportExportService
{
    /**
     * @param array{filas: list<array<string, mixed>>, totales: array<string, mixed>, pendientes: list<array<string, mixed>>} $report
     */
    public function toExcel(array $report, string $agrupacion): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte');

        $headers = $agrupacion === 'dia'
            ? ['Fecha', 'Núm.', 'Importe', 'Transferencia', 'Cheque', 'Efectivo', 'Yappy', 'Otros', 'Total Caja']
            : ['Año', 'Mes', 'Núm.', 'Importe', 'Transferencia', 'Cheque', 'Efectivo', 'Yappy', 'Otros', 'Total Caja'];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);

        $rows = array_map(fn (array $f): array => $this->rowValues($f, $agrupacion), $report['filas']);
        $rows[] = $this->rowValues(['fecha' => 'Total del período'] + $report['totales'], $agrupacion, esTotal: true);

        // strictNullComparison: con la comparación laxa por defecto `0.0 == null` y PhpSpreadsheet omitiría
        // las celdas en cero (saldrían vacías en vez de $0.00) — solo `null` cuenta como celda vacía.
        $sheet->fromArray($rows, null, 'A2', strictNullComparison: true);

        // SCRUM-935 — Importe + las 5 columnas de medio de pago + Total Caja son moneda; "Núm." y
        // las columnas de fecha/año/mes no. Mismo orden en ambas agrupaciones, solo cambia el offset.
        $firstMoneyCol = $agrupacion === 'dia' ? 3 : 4;
        ExcelMoney::apply($sheet, Coordinate::stringFromColumnIndex($firstMoneyCol).'2:'.Coordinate::stringFromColumnIndex(count($headers)).(count($rows) + 1));

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($report['pendientes'] !== []) {
            $pendientesSheet = $spreadsheet->createSheet();
            $pendientesSheet->setTitle('Pendientes');
            $pendientesSheet->fromArray(['Fecha', 'N° Factura', 'Proyecto', 'Monto'], null, 'A1');
            $pendientesSheet->getStyle('A1:D1')->getFont()->setBold(true);
            $pendientesSheet->fromArray(array_map(fn (array $p): array => [
                $p['fecha'], $p['factura'], $p['proyecto'] ?? '—', ExcelMoney::value($p['monto']),
            ], $report['pendientes']), null, 'A2', strictNullComparison: true);
            ExcelMoney::apply($pendientesSheet, 'D2:D'.(count($report['pendientes']) + 1));
            foreach (range(1, 4) as $col) {
                $pendientesSheet->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'client_collection_report_');
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

    /** @param array<string, mixed> $fila
     *  @return list<string|int|float|null> */
    private function rowValues(array $fila, string $agrupacion, bool $esTotal = false): array
    {
        $labelCols = $agrupacion === 'dia'
            ? [(string) $fila['fecha']]
            : ($esTotal ? [(string) $fila['fecha'], null] : [(string) $fila['anio'], (string) $fila['mes']]);

        return [...$labelCols,
            (int) $fila['num'],
            ExcelMoney::value($fila['importe']),
            ExcelMoney::value($fila['transferencia']),
            ExcelMoney::value($fila['cheque']),
            ExcelMoney::value($fila['efectivo']),
            ExcelMoney::value($fila['yappy']),
            ExcelMoney::value($fila['otros']),
            ExcelMoney::value($fila['total_caja']),
        ];
    }
}
