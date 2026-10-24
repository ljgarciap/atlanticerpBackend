<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Support\ExcelMoney;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Excel de la vista de validación de pedidos gemelos de ICG: mismas columnas que la pantalla, con el filtro aplicado y una fila de totales. */
final class PedidosGemelosExportService
{
    /**
     * @param list<array<string, mixed>>   $filas
     * @param array<string, float|int>     $totales
     */
    public function toExcel(array $filas, array $totales): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pedidos gemelos ICG');

        $headers = ['Pedido ICG', 'Pedido AtlanticERP', 'Cliente', 'RUC', 'DV', 'Vendedor', 'Fecha', 'Hoja', 'Etapa', 'Total ICG', 'Facturado en ICG', 'Pendiente', 'Facturado en AtlanticERP', 'Saldo', 'Advertencias'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:O1')->getFont()->setBold(true);

        $rows = array_map(static fn (array $f): array => [
            $f['icg'], $f['order_number'], (string) $f['cliente'], (string) ($f['ruc'] ?? ''), (string) ($f['dv'] ?? ''), (string) ($f['vendedor'] ?? ''),
            (string) ($f['fecha'] ?? ''), (string) ($f['hoja'] ?? ''), $f['etapa'],
            ExcelMoney::value($f['total_icg']), ExcelMoney::value($f['facturado_icg']), ExcelMoney::value($f['pendiente']),
            ExcelMoney::value($f['facturado_atlanticerp']), ExcelMoney::value($f['saldo']), implode('; ', $f['advertencias']),
        ], $filas);
        $rows[] = ['Total ('.$totales['pedidos'].' pedidos)', null, null, null, null, null, null, null, null,
            ExcelMoney::value($totales['total_icg']), null, ExcelMoney::value($totales['pendiente']),
            ExcelMoney::value($totales['facturado_atlanticerp']), ExcelMoney::value($totales['saldo']), null];

        $sheet->fromArray($rows, null, 'A2', strictNullComparison: true);
        $lastRow = count($rows) + 1;
        ExcelMoney::apply($sheet, "J2:J{$lastRow}");
        ExcelMoney::apply($sheet, "K2:N{$lastRow}");
        foreach (range(1, 15) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'pedidos_gemelos_');
        if ($tmpPath === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal para el export a Excel.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tmpPath);
            $bytes = file_get_contents($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
        if ($bytes === false) {
            throw new \RuntimeException('No se pudo leer el Excel generado.');
        }

        return $bytes;
    }
}
