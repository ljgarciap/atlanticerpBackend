<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 8 — Estado de Cuenta (SCRUM-533, REQ-456 RN2). Excel sigue el mismo patrón que
 * `InvoiceExportService::toExcel()` (PhpSpreadsheet). Batch 9 (SCRUM-538, REQ-460) agrega la tabla
 * de movimientos debajo del bloque de encabezado — mismas columnas que la tabla en pantalla
 * (`EstadoCuentaPage.tsx`), sin distinguir agrupado/plano acá (RN2 pide "las mismas columnas", no
 * replicar los 2 modos de vista — la columna "Proyecto" sola ya da ese contexto en cada fila).
 *
 * PDF/impresión (REQ-456 Escenario 1, "se abre el diálogo de impresión del navegador con el
 * documento formateado igual que en pantalla") es intencionalmente `window.print()` del lado del
 * frontend (clases `print:hidden` de Tailwind) — no un PDF generado acá, a diferencia del recibo
 * de Cobros (Batch 7). El propio criterio de aceptación del ticket describe el diálogo nativo del
 * navegador, no una descarga.
 */
final class AccountStatementExportService
{
    private const REGIMEN_LABELS = [
        'con_itbms' => 'Con ITBMS',
        'sin_itbms' => 'Sin ITBMS',
    ];

    private const TARIFA_LABELS = [
        'public'  => 'Precio Público',
        'project' => 'Precio Proyecto',
        'special' => 'Precio Especial',
        'partner' => 'Precio Socio',
        'premium' => 'Tarifa Premium',
    ];

    /** @param array<string, mixed> $detail Salida de `AccountStatementService::detail()`. */
    public function toExcel(array $detail): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Estado de cuenta');

        $headerRows = [
            ['Cliente', (string) $detail['cliente']],
            ['Proyecto', $detail['proyecto'] !== null ? (string) $detail['proyecto'] : 'Todos los proyectos'],
            ['Tarifa', self::TARIFA_LABELS[$detail['tarifa']] ?? (string) ($detail['tarifa'] ?? '—')],
            ['Régimen fiscal', self::REGIMEN_LABELS[$detail['regimen_fiscal']] ?? (string) $detail['regimen_fiscal']],
            ['Términos de pago', (string) $detail['terminos_pago']],
        ];
        if (array_key_exists('saldo', $detail)) {
            $headerRows[] = [$detail['saldo_a_favor'] ? 'Saldo a favor del cliente' : 'Saldo actual', number_format(abs((float) $detail['saldo']), 2)];
            $headerRows[] = ['Pago a', (string) ($detail['pago_a'] ?? '—')];
            $headerRows[] = ['Cuenta número', (string) ($detail['cuenta_pago'] ?? '—')];
            $headerRows[] = ['Responsable', (string) ($detail['responsable'] ?? '—')];
        }

        $sheet->fromArray($headerRows, null, 'A1');
        $sheet->getStyle('A1:A'.count($headerRows))->getFont()->setBold(true);

        /** @var list<array<string, mixed>> $movements */
        $movements = is_array($detail['movimientos'] ?? null) ? $detail['movimientos'] : [];
        if ($movements !== []) {
            $tableStart = count($headerRows) + 2;
            $columns    = ['Fecha', 'Proyecto', 'N° Factura', 'Concepto', 'N° Cotización', 'Guía de Entrega', 'Débito', 'Crédito', 'Saldo'];
            $sheet->fromArray($columns, null, 'A'.$tableStart);
            $sheet->getStyle('A'.$tableStart.':I'.$tableStart)->getFont()->setBold(true);

            $data = array_map(fn (array $m): array => [
                (string) $m['fecha'],
                (string) ($m['proyecto'] ?? '—'),
                (string) $m['numero_factura'],
                $m['tipo'] === 'factura' ? 'Factura' : 'Cobro',
                (string) ($m['cotizacion_folio'] ?? '—'),
                $m['guia_entrega'] ? 'Sí' : '—',
                number_format((float) $m['debito'], 2),
                number_format((float) $m['credito'], 2),
                number_format((float) $m['saldo'], 2),
            ], $movements);
            $sheet->fromArray($data, null, 'A'.($tableStart + 1));
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
