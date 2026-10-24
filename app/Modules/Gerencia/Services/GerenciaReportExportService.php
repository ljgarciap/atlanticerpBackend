<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Services;

use App\Shared\Support\PdfLogo;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * SCRUM-862 — export del reporte de Gerencia ("Exportar reporte", antes un botón placeholder
 * deshabilitado). Mismo patrón (dompdf + PhpSpreadsheet) que
 * `App\Modules\AdminCont\Services\CashPositionExportService`.
 *
 * Regla del módulo (ver app/Modules/Gerencia/CLAUDE.md): nunca reimplementar una métrica en
 * paralelo — este servicio solo formatea el payload ya calculado por
 * `GerenciaHomeService::home()`, nunca vuelve a calcular un KPI por su cuenta.
 *
 * Alcance del "reporte" exportable: las 3 secciones que de verdad responden a los filtros de
 * fecha/vendedor/cliente de la pantalla (kpis, facturacion, proyectos) — 'aprobaciones',
 * 'salud_modulos' y 'agendas' son widgets operativos de estado actual, no datos de un rango, así
 * que no tiene sentido "exportarlos" como parte de un reporte fechado.
 */
final class GerenciaReportExportService
{
    /**
     * @param array<string, mixed> $homeData resultado de GerenciaHomeService::home()
     * @param array{desde: string, hasta: string, vendedor?: string|null, cliente?: string|null} $filterLabels
     */
    public function toPdf(array $homeData, array $filterLabels): string
    {
        // SCRUM-892/893 — isPhpEnabled requerido para el pie de página real (page_text(), ver
        // `pdf.partials.footer`), mismo mecanismo que ServiceQuotePdfService/QuotePdfService.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('gerencia.report-pdf', [
                'data'         => $homeData,
                'filters'      => $filterLabels,
                'generatedAt'  => now(),
                'logoDataUri'  => PdfLogo::dataUri(),
            ])
            ->setPaper('letter')
            ->output();
    }

    /**
     * @param array<string, mixed> $homeData resultado de GerenciaHomeService::home()
     * @param array{desde: string, hasta: string, vendedor?: string|null, cliente?: string|null} $filterLabels
     */
    public function toExcel(array $homeData, array $filterLabels): string
    {
        $spreadsheet = new Spreadsheet();

        $this->writeResumenSheet($spreadsheet, $homeData, $filterLabels);
        $this->writeMensualSheet($spreadsheet, 'Facturación mensual', $homeData['facturacion']['chart_mensual'] ?? []);
        $this->writeMensualSheet($spreadsheet, 'Proyectos cerrados mensual', $homeData['proyectos']['chart_mensual'] ?? []);

        // La hoja "Resumen" se agrega primero pero PhpSpreadsheet arranca con una hoja default
        // vacía — se remueve acá, después de poblar todo, para no tocar los índices mientras se
        // escribe.
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'gerencia_report_export_');
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

    /**
     * @param array<string, mixed> $homeData
     * @param array{desde: string, hasta: string, vendedor?: string|null, cliente?: string|null} $filterLabels
     */
    private function writeResumenSheet(Spreadsheet $spreadsheet, array $homeData, array $filterLabels): void
    {
        $kpis        = $homeData['kpis'] ?? [];
        $facturacion = $homeData['facturacion']['kpis'] ?? [];
        $proyectos   = $homeData['proyectos']['kpis'] ?? [];

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen');

        $rows = [
            ['Reporte de Gerencia'],
            ['Rango', "{$filterLabels['desde']} — {$filterLabels['hasta']}"],
            ['Vendedor/Diseñador', $filterLabels['vendedor'] ?? 'Todos'],
            ['Cliente', $filterLabels['cliente'] ?? 'Todos'],
            [],
            ['Variación margen (facturado vs. cotizado)'],
            ['Facturado', number_format((float) ($kpis['variacion_margen']['facturado'] ?? 0), 2)],
            ['Cotizado', number_format((float) ($kpis['variacion_margen']['cotizado'] ?? 0), 2)],
            ['Variación (pts)', $kpis['variacion_margen']['variacion_pct'] ?? 'N/A'],
            [],
            ['Facturación'],
            ['Facturado (año)', number_format((float) ($facturacion['facturado_anio'] ?? 0), 2)],
            ['Facturado (período filtrado)', number_format((float) ($facturacion['facturado_mes'] ?? 0), 2)],
            ['Cantidad de facturas (período filtrado)', $facturacion['facturas_count'] ?? 0],
            [],
            ['Proyectos'],
            ['Cerrados (año)', $proyectos['cerrados_anio_count'] ?? 0],
            ['Monto cerrado (año)', number_format((float) ($proyectos['cerrados_anio'] ?? 0), 2)],
            ['Cerrados (período filtrado)', $proyectos['cerrados_mes_count'] ?? 0],
            ['Monto cerrado (período filtrado)', number_format((float) ($proyectos['cerrados_mes'] ?? 0), 2)],
            ['Proyectos activos', $kpis['proyectos_activos'] ?? 0],
        ];

        $sheet->fromArray($rows, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        foreach (['A6', 'A11', 'A16'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }
        foreach (['A', 'B'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /** @param list<array{mes: string, monto: float}> $chartMensual */
    private function writeMensualSheet(Spreadsheet $spreadsheet, string $title, array $chartMensual): void
    {
        $headers = ['Mes', 'Monto'];
        $rows    = array_map(
            fn (array $row): array => [(string) $row['mes'], number_format((float) $row['monto'], 2)],
            $chartMensual,
        );

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));

        $sheet->fromArray($headers, null, 'A1');
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }
    }
}
