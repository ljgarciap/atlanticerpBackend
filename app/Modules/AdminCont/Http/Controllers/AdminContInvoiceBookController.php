<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Http\Resources\AdminCont\InvoiceBookDocumentResource;
use App\Modules\AdminCont\Services\InvoiceBookExportService;
use App\Modules\AdminCont\Services\InvoiceBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 23 Grupo 3 (SCRUM-661→664, REQ-584→587) — "Libro de Facturas" (4M3). Controller fino,
 * delega todo a `InvoiceBookService`.
 */
class AdminContInvoiceBookController
{
    public function __construct(
        private readonly InvoiceBookService $book,
        private readonly InvoiceBookExportService $export,
    ) {}

    /**
     * GET /api/admin-contab/reports/libro-facturas — `documentos` se envuelve en
     * `InvoiceBookDocumentResource::collection()` recién acá (capa HTTP), nunca dentro de
     * `InvoiceBookService::documents()` — ese método lo reusa `InvoiceBookExportService` con el
     * array plano. Envolver en el controller (no en el service) es lo que le da a Scramble
     * (generador de OpenAPI/tipos TS) el shape completo de la fila, ver
     * `docs/architecture/typescript-types-from-backend.md`.
     */
    public function index(Request $request): JsonResponse
    {
        $report = $this->build($request);

        return response()->json([
            'resumen'    => $report['resumen'],
            'documentos' => InvoiceBookDocumentResource::collection($report['documentos']),
        ]);
    }

    /** GET /api/admin-contab/reports/libro-facturas/excel */
    public function excel(Request $request): StreamedResponse|JsonResponse
    {
        $report = $this->build($request);

        // RN1 REQ-587 — defensa server-side, aunque el frontend ya bloquee el botón sin datos.
        if ($report['documentos'] === []) {
            return response()->json(['message' => 'No hay documentos para descargar con el filtro actual.'], 422);
        }

        $bytes    = $this->export->toExcel($report);
        $filename = 'Libro_de_facturas_'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(
            fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array{resumen: array<string, mixed>, documentos: list<array<string, mixed>>} */
    private function build(Request $request): array
    {
        // Bug real encontrado en Pre-QA (2026-08-27): este match comparaba contra las etiquetas de
        // VISUALIZACIÓN ('Factura'/'Nota de Crédito', las mismas que ya devuelve `documento.tipo`
        // en el payload) en vez del vocabulario real que manda el frontend (`InvoiceBookTipo` =
        // 'factura'|'nota_credito', el mismo `value` de las <option> del <select> en
        // LibroFacturasPage.tsx) — el filtro de tipo (RN2 REQ-584) nunca filtraba nada, para
        // ningún valor real que el <select> pudiera enviar.
        $tipoParam = $request->query('tipo');
        $tipo      = in_array($tipoParam, ['factura', 'nota_credito'], true) ? $tipoParam : null;

        return $this->book->documents($request->query('desde'), $request->query('hasta'), $tipo);
    }
}
