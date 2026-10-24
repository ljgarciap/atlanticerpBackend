<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Support;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * SCRUM-935 — formato de moneda de los Excel de Reportes de Admin&Cont. Antes cada export
 * escribía los montos con `number_format()` (TEXTO "1,234.50": no sumable, sin símbolo, alineado
 * como string); ahora la celda lleva el NÚMERO real (redondeado a 2 decimales) y este formato de
 * celda hace que Excel/LibreOffice lo muestre como `$1,234.50` — y un negativo (nota de crédito)
 * como `-$1,234.50`. Un solo lugar para el código de formato, compartido por los 4 reportes.
 */
final class ExcelMoney
{
    public const FORMAT = '$#,##0.00';

    /** Valor numérico de celda para un monto: float redondeado a 2 decimales (nunca string). */
    public static function value(mixed $amount): float
    {
        return round((float) $amount, 2);
    }

    /** Aplica el formato de moneda al rango (ej. `C2:I9`). */
    public static function apply(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::FORMAT);
    }
}
