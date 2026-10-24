<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Support\ExcelMoney;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-520) — exporta la vista de Flujo de Caja activa
 * (Proyectado o Real). RN3: nunca se llama para Real+Hoy, el controller rechaza esa combinación
 * antes de llegar acá — ver ADR-SCRUM597-601-batch18-arqueo-caja.md. Mismo patrón
 * (dompdf + PhpSpreadsheet) que `InvoiceExportService`/`CommissionExportService`.
 */
final class CashPositionExportService
{
    /** @param array<string, mixed> $data */
    public function toPdf(string $view, array $data): string
    {
        $template = $view === 'proyectado' ? 'admin-cont.cash-flow-proyectado-pdf' : 'admin-cont.cash-flow-real-pdf';

        return Pdf::loadView($template, [...$data, 'generatedAt' => now()])
            ->setPaper('letter')
            ->output();
    }

    /** @param array<string, mixed> $data */
    public function toExcel(string $view, array $data): string
    {
        return $view === 'proyectado' ? $this->proyectadoExcel($data) : $this->realExcel($data);
    }

    /** @param array<string, mixed> $data */
    private function proyectadoExcel(array $data): string
    {
        $headers = ['Tipo', 'Concepto', 'Referencia', 'Monto'];
        $rows = [];
        /** @var list<array<string, mixed>> $entradas */
        $entradas = $data['entradas'];
        /** @var list<array<string, mixed>> $salidas */
        $salidas = $data['salidas'];

        foreach ($entradas as $e) {
            $rows[] = ['Entrada', (string) $e['nombre'], (string) $e['referencia'], ExcelMoney::value($e['monto'])];
        }
        foreach ($salidas as $s) {
            $rows[] = ['Salida', (string) $s['nombre'], (string) $s['referencia'], ExcelMoney::value(-1 * (float) $s['monto'])];
        }
        $rows[] = [null, null, 'Neto proyectado', ExcelMoney::value($data['neto'])];

        return $this->buildSheet('Flujo de caja - Proyectado', $headers, $rows, 'D');
    }

    /** @param array<string, mixed> $data */
    private function realExcel(array $data): string
    {
        $headers = ['Fecha', 'Concepto', 'Origen', 'Entrada', 'Salida', 'Saldo acumulado'];
        $rows = [];
        /** @var list<array<string, mixed>> $movimientos */
        $movimientos = $data['movimientos'];

        foreach ($movimientos as $m) {
            $rows[] = [
                (string) $m['fecha'], (string) $m['concepto'], (string) $m['origen'],
                ExcelMoney::value($m['entrada']), ExcelMoney::value($m['salida']),
                ExcelMoney::value($m['saldo_acumulado']),
            ];
        }

        return $this->buildSheet('Flujo de caja - Real', $headers, $rows, 'D:F');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  string|null  $moneyRange  Rango de columnas de moneda (ej. 'D' o 'D:F'), sin fila — se calcula acá.
     */
    private function buildSheet(string $title, array $headers, array $rows, ?string $moneyRange = null): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($rows !== []) {
            // strictNullComparison: `0.0 == null` con la comparación laxa por defecto omitiría montos en
            // cero (saldrían vacíos en vez de $0.00) — solo `null` cuenta como celda vacía.
            $sheet->fromArray($rows, null, 'A2', strictNullComparison: true);
            if ($moneyRange !== null) {
                $lastRow = count($rows) + 1;
                [$fromCol, $toCol] = str_contains($moneyRange, ':') ? explode(':', $moneyRange) : [$moneyRange, $moneyRange];
                ExcelMoney::apply($sheet, "{$fromCol}2:{$toCol}{$lastRow}");
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'cash_position_export_');
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
