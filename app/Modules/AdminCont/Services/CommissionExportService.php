<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 14 (SCRUM-575, REQ-498 RN2) — exporta el reporte de Comisiones Internas respetando el
 * filtro de mes+vendedor activo al momento de exportar.
 */
final class CommissionExportService
{
    /** @param array<string, mixed> $data */
    public function toPdf(array $data): Response
    {
        $pdf = Pdf::loadView('admin-contab.commissions-internal-pdf', ['data' => $data]);

        return $pdf->download("comisiones-internas-{$data['mes']}.pdf");
    }

    /** REQ-507 (Batch 15) — documento "Estado de cuenta de comisiones" de un vendedor.
     * @param array<string, mixed> $data */
    public function toAccountStatementPdf(array $data): Response
    {
        $pdf = Pdf::loadView('admin-contab.commission-account-statement-pdf', ['data' => $data]);

        return $pdf->download("estado-cuenta-comisiones-{$data['vendedor_nombre']}-{$data['mes']}.pdf");
    }

    /** @param array<string, mixed> $data */
    public function toExcel(array $data): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Comisiones Internas');

        $sheet->fromArray(['Vendedor', 'Total pedidos', '% comisión', 'Ya pagada', 'Por pagar', 'Pendiente de cobro'], null, 'A1');

        $row = 2;
        foreach ($data['vendedores'] as $v) {
            $sheet->fromArray([
                $v['vendedor_nombre'],
                $v['total_pedidos_mes'],
                $v['porcentaje'] . '%' . ($v['porcentaje_fijo'] ? '' : ' (provisional)'),
                $v['pagada'],
                $v['por_pagar'],
                $v['pendiente_cobro'],
            ], null, "A{$row}");
            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $filename = "comisiones-internas-{$data['mes']}.xlsx";

        return new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
