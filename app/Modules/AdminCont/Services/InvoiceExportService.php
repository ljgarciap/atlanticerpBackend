<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use App\Modules\AdminCont\Support\ExcelMoney;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Batch 3 de Facturación (SCRUM-519→523, REQ-446) — exporta la tabla de entregas/facturas ya
 * filtrada por `InvoiceService::list(view: 'plana')` (RN1: nunca recalcula ni ignora los filtros
 * activos, el controller ya le pasa las filas exactas a exportar). Mismas columnas visibles en
 * pantalla (RN2) — ver `FacturacionPage.tsx`: Entrega/Hito, Cliente, Vendedor (SCRUM-1175), N° Factura,
 * N° Cotización, Monto, Estado. Excel sigue el patrón de `App\Modules\Bodega\Services\OrderExportService`
 * (PhpSpreadsheet); PDF sigue el patrón de los `*PdfService` de Servicios/VentasDiseno (dompdf).
 */
final class InvoiceExportService
{
    private const ESTADO_LABELS = [
        'pendiente-facturar' => 'Pendiente por facturar',
        'facturada' => 'Facturada',
    ];

    /** @param list<array<string, mixed>> $rows */
    public function toExcel(array $rows): string
    {
        $headers = ['Entrega / Hito', 'Cliente', 'Vendedor', 'N° Factura', 'N° Cotización', 'Monto', 'Estado'];
        // Monto (índice 5) numérico para Excel — distinto de toPdf(), que lo deja como string
        // formateado porque el PDF renderiza texto, no celdas.
        $data = array_map(function (array $r): array {
            [$etiqueta, $numeroFactura, $cotizacionFolio, $estado] = $this->labelParts($r);

            return [$etiqueta, (string) ($r['cliente'] ?? '—'), $this->vendedores($r), $numeroFactura, $cotizacionFolio, ExcelMoney::value($r['monto'] ?? 0), $estado];
        }, $rows);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Facturación');

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($data !== []) {
            // strictNullComparison: `0.0 == null` con la comparación laxa por defecto omitiría montos en
            // cero (saldrían vacíos en vez de $0.00) — solo `null` cuenta como celda vacía.
            $sheet->fromArray($data, null, 'A2', strictNullComparison: true);
            ExcelMoney::apply($sheet, 'F2:F'.(count($data) + 1));
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
        [$etiqueta, $numeroFactura, $cotizacionFolio, $estado] = $this->labelParts($row);

        return [$etiqueta, (string) ($row['cliente'] ?? '—'), $this->vendedores($row), $numeroFactura, $cotizacionFolio, number_format((float) ($row['monto'] ?? 0), 2), $estado];
    }

    /**
     * SCRUM-1175 — responsables del pedido separados por coma; «—» si no tiene (facturas migradas o de venta chica).
     *
     * @param array<string, mixed> $row
     */
    private function vendedores(array $row): string
    {
        $names = $row['vendedores'] ?? [];

        return is_array($names) && $names !== [] ? implode(', ', $names) : '—';
    }

    /**
     * Columnas no-monetarias, compartidas por toExcel()/toPdf() — cada una decide por separado
     * cómo representar "Monto" (celda numérica vs. string formateado para el PDF).
     *
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function labelParts(array $row): array
    {
        // SCRUM-929 (F3) — el "(n/N)" solo va cuando la fila ES una entrega: una factura `parcial`, o
        // una entrega pendiente en un pedido facturable por entrega. Una factura `completo` (o la fila
        // única de un pedido que solo admite completo, p. ej. todo lo legado) representa el PEDIDO ENTERO
        // y queda con `order_number` a secas, como en SCRUM-876.
        $entrega = $row['entrega'] ?? null;
        $etiqueta = (string) ($row['order_number'] ?? '');
        $tipo = $row['tipo_factura'] ?? null;
        $esEntrega = $tipo === 'parcial' || ($tipo === null && ($row['facturacion_por_entrega'] ?? false) === true);
        $secuencia = is_array($entrega) ? ($entrega['sequence_in_family'] ?? null) : null;
        $totalEntregas = is_array($entrega) ? ($entrega['total_in_family'] ?? null) : null;
        if ($esEntrega && $secuencia !== null && $totalEntregas !== null && (int) $totalEntregas > 1) {
            $etiqueta .= " ({$secuencia}/{$totalEntregas})";
        }

        return [
            $etiqueta,
            (string) ($row['numero_factura'] ?? '—'),
            (string) ($row['cotizacion_folio'] ?? '—'),
            self::ESTADO_LABELS[$row['estado'] ?? ''] ?? (string) ($row['estado'] ?? ''),
        ];
    }
}
