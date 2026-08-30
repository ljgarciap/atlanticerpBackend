<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Modules\VentasDiseno\Http\Requests\SendCatalogPdfRequest;
use App\Modules\VentasDiseno\Http\Requests\StoreCatalogTechnicalSheetRequest;
use App\Modules\VentasDiseno\Services\CatalogPdfService;
use App\Modules\VentasDiseno\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * SCRUM-695→701 (REQ-615→621, Batch E del Epic CRM SCRUM-332) — pantalla "Catálogo". Nombre
 * distinto de `CatalogProductController` a propósito (ese es el buscador REQ-036 dentro de
 * Cotización, endpoint separado que sigue vivo sin cambios) — ver docblock de `CatalogService`
 * para el detalle completo de qué campos nunca se exponen acá (RN1 de REQ-615, criterio duro de
 * seguridad de datos).
 *
 * PERMISOS — el ticket REQ-618 pide explícitamente "sin restricción de rol específica" para el
 * upload de ficha técnica: gateado con el mismo `ventas_diseno.read` que el resto de la pantalla,
 * no un `.edit`/`.write` — decisión ya tomada en la spec, no de este controller.
 */
final class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogService $service,
        private readonly CatalogPdfService $pdfService,
    ) {}

    /** REQ-615/616/617 — listado con campos restringidos, filtros combinables, paginado (SCRUM-754, ver docblock de CatalogService). */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->list($request));
    }

    /** Detalle de un producto — mismo formato restringido que el listado (REQ-616 RN "click abre el mismo modal"). */
    public function show(int $id): JsonResponse
    {
        $product = $this->service->find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($product);
    }

    /** REQ-618 RN2 — "Ver documento": presigned URL de 15 min, el frontend la abre en pestaña nueva. */
    public function technicalSheetUrl(int $id): JsonResponse
    {
        $product = $this->service->findModel($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $url = $this->service->technicalSheetUrl($product);
        if ($url === null) {
            return response()->json(['message' => 'No technical sheet uploaded'], 404);
        }

        return response()->json(['url' => $url]);
    }

    /** REQ-618 RN1/RN4 — sube/reemplaza la ficha técnica; el frontend invalida su query al recibir 200. */
    public function uploadTechnicalSheet(StoreCatalogTechnicalSheetRequest $request, int $id): JsonResponse
    {
        $product = $this->service->findModel($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');
        $this->service->uploadTechnicalSheet($product, $file);

        return response()->json([
            'id'                          => $product->id,
            'has_technical_sheet'         => true,
            'technical_sheet_filename'    => $product->technical_sheet_filename,
            'technical_sheet_uploaded_at' => $product->technical_sheet_uploaded_at?->toIso8601String(),
        ]);
    }

    /**
     * SCRUM-702 (REQ-622) — PDF dinámico real, generado con dompdf (ver `CatalogPdfService`).
     * `mode=completo` trae TODOS los productos activos (RN4, ignora cualquier `product_ids`
     * enviado); `mode=seleccionados` exige `product_ids` no vacío (422 vía `SendCatalogPdfRequest`
     * si falta). RN1 — el array que llega a la vista Blade nunca incluye stock/disponibilidad, ver
     * docblock de `CatalogPdfService::formatForPdf()`. Descarga forzada, mismo patrón que
     * `exampleCatalogPdf()`.
     */
    public function sendPdf(SendCatalogPdfRequest $request): Response
    {
        $data = $request->validated();

        $pdf = $this->pdfService->generate($data['mode'], $data['product_ids'] ?? []);

        $filename = 'Catalogo-Illuminations-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }
}
