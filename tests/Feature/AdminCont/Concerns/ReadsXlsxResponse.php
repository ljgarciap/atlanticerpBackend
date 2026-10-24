<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont\Concerns;

use App\Modules\AdminCont\Support\ExcelMoney;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** SCRUM-935 — lectura del xlsx descargado y aserciones de "celda numérica con formato de moneda". */
trait ReadsXlsxResponse
{
    /** @param TestResponse<\Symfony\Component\HttpFoundation\StreamedResponse> $response */
    private function loadWorkbook(TestResponse $response): Spreadsheet
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'report_xlsx_');
        file_put_contents($tmpPath, $response->streamedContent());
        try {
            return IOFactory::createReader('Xlsx')->load($tmpPath);
        } finally {
            @unlink($tmpPath);
        }
    }

    /** La celda guarda un NÚMERO real (no texto "1,234.50") con el formato `$#,##0.00`. */
    private function assertMoneyCell(Worksheet $sheet, string $coordinate, float $expected): void
    {
        $cell = $sheet->getCell($coordinate);
        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), "{$coordinate} debe ser numérica, no texto");
        $this->assertEqualsWithDelta($expected, (float) $cell->getValue(), 0.001, "{$coordinate} valor");
        $this->assertSame(ExcelMoney::FORMAT, $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode(), "{$coordinate} formato de moneda");
    }
}
