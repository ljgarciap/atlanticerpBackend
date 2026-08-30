<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 3 de Facturación (SCRUM-519→523, REQ-446) — exporta la tabla de entregas/facturas ya
 * filtrada por `InvoiceService::list(view: 'plana')` (RN1: nunca recalcula ni ignora los filtros
 * activos, el controller ya le pasa las filas exactas a exportar). Mismas columnas visibles en
 * pantalla (RN2) — ver `FacturacionPage.tsx`: Entrega/Hito, N° Factura, N° Cotización, Monto,
 * Estado. Excel sigue el patrón de `App\Modules\Bodega\Services\OrderExportService`
 * (PhpSpreadsheet); PDF sigue el patrón de los `*PdfService` de Servicios/VentasDiseno (dompdf).
 */
final class InvoiceExportService
{
    private const ESTADO_LABELS = [
        'pendiente-facturar' => 'Pendiente por facturar',
        'facturada'          => 'Facturada',
    ];

    /** @param list<array<string, mixed>> $rows */
    public function toExcel(array $rows): string
    {
        $headers = ['Entrega / Hito', 'N° Factura', 'N° Cotización', 'Monto', 'Estado'];
        $data    = array_map(fn (array $r): array => $this->rowValues($r), $rows);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Facturación');

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($data !== []) {
            $sheet->fromArray($data, null, 'A2');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'invoice_export_');
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

    /** @param list<array<string, mixed>> $rows */
    public function toPdf(array $rows): string
    {
        $data = array_map(fn (array $r): array => $this->rowValues($r), $rows);

        return Pdf::loadView('admin-cont.invoice-export-pdf', ['rows' => $data, 'generatedAt' => now()])
            ->setPaper('letter')
            ->output();
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function rowValues(array $row): array
    {
        return [
            (string) ($row['order_number'] ?? ''),
            (string) ($row['numero_factura'] ?? '—'),
            (string) ($row['cotizacion_folio'] ?? '—'),
            number_format((float) ($row['monto'] ?? 0), 2),
            self::ESTADO_LABELS[$row['estado'] ?? ''] ?? (string) ($row['estado'] ?? ''),
        ];
    }
}
