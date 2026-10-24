<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Support\ExcelMoney;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 8 — Estado de Cuenta (SCRUM-533, REQ-456 RN2). Excel sigue el mismo patrón que
 * `InvoiceExportService::toExcel()` (PhpSpreadsheet). Batch 9 (SCRUM-538, REQ-460) agrega la tabla
 * de movimientos debajo del bloque de encabezado — mismas columnas que la tabla en pantalla
 * (`EstadoCuentaPage.tsx`), sin distinguir agrupado/plano acá (RN2 pide "las mismas columnas", no
 * replicar los 2 modos de vista — la columna "Proyecto" sola ya da ese contexto en cada fila).
 *
 * SCRUM-857 (REQ-456) — el PDF pasó de ser `window.print()` del lado del frontend (decisión
 * original de este batch, ya no vigente) a un documento real generado en servidor, igual que
 * Cotizaciones — ver `AccountStatementPdfService`. Este export a Excel no cambió de mecanismo,
 * solo se le agregaron las filas "Tipo de cuenta"/"Banco" del mismo REQ-458.
 */
final class AccountStatementExportService
{
    private const REGIMEN_LABELS = [
        'con_itbms' => 'Con ITBMS',
        'sin_itbms' => 'Sin ITBMS',
    ];

    private const TARIFA_LABELS = [
        'public' => 'Precio Público',
        'project' => 'Precio Proyecto',
        'special' => 'Precio Especial',
        'partner' => 'Precio Socio',
        'premium' => 'Tarifa Premium',
    ];

    /** Mismo catálogo que `AdminContBankAccount::TIPO_*` — sin acceso a i18n del frontend acá. */
    private const TIPO_CUENTA_LABELS = [
        'corriente' => 'Cuenta corriente',
        'ahorro' => 'Cuenta de ahorro',
        'tarjeta_credito' => 'Tarjeta de crédito',
    ];

    /** @param array<string, mixed> $detail Salida de `AccountStatementService::detail()`. */
    public function toExcel(array $detail): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Estado de cuenta');

        $headerRows = [
            ['Cliente', (string) $detail['cliente']],
            ['Proyecto', $detail['proyecto'] !== null ? (string) $detail['proyecto'] : 'Todos los proyectos'],
            ['Tarifa', self::TARIFA_LABELS[$detail['tarifa']] ?? (string) ($detail['tarifa'] ?? '—')],
            ['Régimen fiscal', self::REGIMEN_LABELS[$detail['regimen_fiscal']] ?? (string) $detail['regimen_fiscal']],
            ['Términos de pago', (string) $detail['terminos_pago']],
        ];
        $saldoRow = null;
        if (array_key_exists('saldo', $detail)) {
            $saldoRow = count($headerRows) + 1;
            $headerRows[] = [$detail['saldo_a_favor'] ? 'Saldo a favor del cliente' : 'Saldo actual', ExcelMoney::value(abs((float) $detail['saldo']))];
            $headerRows[] = ['Pago a', (string) ($detail['pago_a'] ?? '—')];
            $headerRows[] = ['Cuenta número', (string) ($detail['numero_cuenta'] ?? '—')];
            $headerRows[] = ['Tipo de cuenta', self::TIPO_CUENTA_LABELS[$detail['tipo_cuenta'] ?? ''] ?? '—'];
            $headerRows[] = ['Banco', (string) ($detail['banco'] ?? '—')];
            $headerRows[] = ['Responsable', (string) ($detail['responsable'] ?? '—')];
        }

        // strictNullComparison: con la comparación laxa por defecto `0.0 == null` PhpSpreadsheet omitiría
        // el saldo en cero (saldría vacío en vez de $0.00) — solo `null` cuenta como celda vacía.
        $sheet->fromArray($headerRows, null, 'A1', strictNullComparison: true);
        $sheet->getStyle('A1:A'.count($headerRows))->getFont()->setBold(true);
        if ($saldoRow !== null) {
            ExcelMoney::apply($sheet, "B{$saldoRow}");
        }

        /** @var list<array<string, mixed>> $movements */
        $movements = is_array($detail['movimientos'] ?? null) ? $detail['movimientos'] : [];
        if ($movements !== []) {
            $tableStart = count($headerRows) + 2;
            $columns = ['Fecha', 'Proyecto', 'N° Factura', 'Concepto', 'N° Cotización', 'Guía de Entrega', 'Débito', 'Crédito', 'Saldo'];
            $sheet->fromArray($columns, null, 'A'.$tableStart);
            $sheet->getStyle('A'.$tableStart.':I'.$tableStart)->getFont()->setBold(true);

            $data = array_map(fn (array $m): array => [
                (string) $m['fecha'],
                (string) ($m['proyecto'] ?? '—'),
                (string) ($m['numero_factura'] ?? '—'),
                [
                    'factura' => 'Factura', 'descuento' => 'Descuento', 'devolucion' => 'Devolución', 'anulacion' => 'Anulación',
                    'compensacion' => 'Compensación', 'devolucion_saldo' => 'Devolución de saldo',
                ][$m['tipo']] ?? 'Cobro',
                (string) ($m['cotizacion_folio'] ?? '—'),
                (string) ($m['guia_entrega_numero'] ?? '—'),
                ExcelMoney::value($m['debito']),
                ExcelMoney::value($m['credito']),
                ExcelMoney::value($m['saldo']),
            ], $movements);
            $dataStart = $tableStart + 1;
            $sheet->fromArray($data, null, 'A'.$dataStart, strictNullComparison: true);
            $lastRow = $dataStart + count($data) - 1;
            ExcelMoney::apply($sheet, "G{$dataStart}:I{$lastRow}");
        }

        foreach (range(1, 9) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'account_statement_export_');
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
