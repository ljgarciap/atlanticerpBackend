<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * SCRUM-224 (ADR-SCRUM224) — extrae el contenido de una factura Excel/CSV a texto plano (tabla)
 * para mandarlo a Claude vía AnthropicClient::completeWithTool(); completeWithDocumentTool() es
 * vision-only (PDF/imagen), no acepta el binario de una hoja de cálculo.
 *
 * Cybersecurity gap #1 (bloqueante de arranque de ADR-SCRUM224): un .xlsx es un ZIP con XML
 * adentro — phpspreadsheet ya bloquea por default `<!DOCTYPE`/`<!ENTITY` en ese XML interno vía
 * Reader\Security\XmlScanner (confirmado leyendo
 * vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Reader/Security/XmlScanner.php — se activa
 * solo con construir el reader, sin configuración adicional). Esta clase agrega una segunda capa
 * de defensa en profundidad: un tope de filas/columnas leídas vía IReadFilter, para no expandir
 * en memoria una hoja anormalmente grande — el límite de tamaño del archivo subido en sí lo
 * aplica el endpoint ANTES de llegar acá (gap #2 del ADR), este tope es adicional, no el único
 * control.
 */
final class InvoiceSpreadsheetExtractor
{
    private const MAX_ROWS = 500;
    private const MAX_COLUMNS = 30;

    public function extractText(string $bytes, string $extension): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'invoice_');
        if ($tmpPath === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal para leer la factura.');
        }

        try {
            file_put_contents($tmpPath, $bytes);

            $readerType = match ($extension) {
                'csv'   => 'Csv',
                'xls'   => 'Xls',
                default => 'Xlsx',
            };

            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class (self::MAX_ROWS, self::MAX_COLUMNS) implements IReadFilter {
                public function __construct(private readonly int $maxRow, private readonly int $maxColumn)
                {
                }

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->maxRow && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumn;
                }
            });

            $spreadsheet = $reader->load($tmpPath);
            $sheet       = $spreadsheet->getActiveSheet();

            return $this->sheetToText($sheet);
        } finally {
            @unlink($tmpPath);
        }
    }

    private function sheetToText(Worksheet $sheet): string
    {
        $lines       = [];
        $highestRow  = min($sheet->getHighestDataRow(), self::MAX_ROWS);

        foreach ($sheet->getRowIterator(1, $highestRow) as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $value = trim((string) $cell->getFormattedValue());
                if ($value !== '') {
                    $cells[] = $value;
                }
            }
            if ($cells !== []) {
                $lines[] = implode(' | ', $cells);
            }
        }

        return implode("\n", $lines);
    }
}
