<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\PreferencesService;
use App\Modules\CRM\Services\CrmSettingsService;
use App\Modules\VentasDiseno\Http\Requests\SendCatalogPdfRequest;
use App\Modules\VentasDiseno\Http\Requests\StoreCatalogTechnicalSheetRequest;
use App\Modules\VentasDiseno\Models\CatalogPdfExport;
use App\Modules\VentasDiseno\Services\CatalogPdfExportService;
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
        private readonly CatalogPdfExportService $pdfExports,
        private readonly CrmSettingsService $crmSettings,
        private readonly PreferencesService $preferences,
    ) {}

    /** REQ-615/616/617 — listado con campos restringidos, filtros combinables, paginado (SCRUM-754, ver docblock de CatalogService). */
    public function index(Request $request): JsonResponse
    {
        // docs/specs/demo-mode.md — hallazgo de Pre-QA/Arquitecto (2026-09-04): esta es la
        // pantalla "Catálogo" real (SCRUM-695+); CatalogProductController::index() es un endpoint
        // DISTINTO (el buscador de Cotización, REQ-036) pese al nombre parecido. Sin este flag,
        // el badge de Modo Demo que muestra el frontend en esta pantalla no tendría ningún filtro
        // real detrás.
        $user          = $this->resolveUser($request);
        $demoModeActive = $user !== null && $this->preferences->isDemoModeActive($user);

        return response()->json($this->service->list($request, $demoModeActive));
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
     * SCRUM-702 (REQ-622) — PDF dinámico real, generado con dompdf (ver `CatalogPdfService`)
     * `mode=completo` trae TODOS los productos activos (RN4, ignora cualquier `product_ids`
     * enviado); `mode=seleccionados` exige `product_ids` no vacío (422 vía `SendCatalogPdfRequest`
     * si falta). RN1 — el array que llega a la vista Blade nunca incluye stock/disponibilidad, ver
     * docblock de `CatalogPdfService::formatForPdf()`. Descarga forzada, mismo patrón que
     * `exampleCatalogPdf()`.
     *
     * SCRUM-998 (docs/specs/scrum998-catalog-pdf-background.md, decisión de Luis 2026-09-29) — hasta
     * `catalog_pdf_max_single_products` productos (500 por defecto) y `catalog_pdf_max_single_photos` fotos (200 por defecto), ambos
     * configurables, sigue siendo el PDF plano síncrono de siempre (200). Por encima, un solo PDF no cabe en una petición (con el catálogo real de ~30K
     * productos dompdf agotaba los 256 MB y PHP-FPM corta a los 30 s): se encola la generación en segundo
     * plano de un ZIP de volúmenes y se responde 202 con el identificador de la exportación; el solicitante
     * recibe un aviso in-app con el enlace de descarga (`GET /catalog/pdf-exports/{id}`).
     */
    public function sendPdf(SendCatalogPdfRequest $request): Response|JsonResponse
    {
        $data = $request->validated();
        $mode = $data['mode'];
        $productIds = $data['product_ids'] ?? [];

        $total = $this->pdfService->countProducts($mode, $productIds);

        // Un PDF plano solo cabe en una petición si tiene pocos productos Y pocas fotos: cada foto remota es una descarga (~83 ms
        // efectivos con 20 en paralelo) y nginx corta a los 60 s, así que 500 productos que todos tengan foto tampoco caben.
        $tooLarge = $total > $this->crmSettings->catalogPdfMaxSingleProducts()
            || $this->pdfService->countPhotos($mode, $productIds) > $this->crmSettings->catalogPdfMaxSinglePhotos();

        if ($tooLarge) {
            $user = $this->resolveUser($request);
            if ($user === null) {
                return response()->json(['message' => trans('auth.unauthenticated')], 401);
            }

            $export = $this->pdfExports->start($user, $mode, $productIds, $total);

            return response()->json([
                'export_id'      => $export->uuid,
                'status'         => $export->status,
                'total_products' => $export->total_products,
                'volumes'        => $export->volumes,
            ], 202);
        }

        $pdf = $this->pdfService->generate($mode, $productIds);

        $filename = 'Catalogo-Atlantic-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * SCRUM-998 — estado de una exportación en segundo plano. Solo el solicitante o un superadmin: para cualquier otra
     * persona responde 404 (igual que una exportación inexistente, no revela que existe). Con `status = done` incluye
     * una presigned URL de 15 min del ZIP.
     */
    public function pdfExport(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var CatalogPdfExport|null $export */
        $export = CatalogPdfExport::where('uuid', $uuid)->first();
        if ($export === null || ($export->requested_by !== $user->id && ! $this->isSuperadmin($request))) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'export_id'      => $export->uuid,
            'status'         => $export->status,
            'total_products' => $export->total_products,
            'volumes'        => $export->volumes,
            'size_bytes'     => $export->size_bytes,
            'error_message'  => $export->error_message,
            'created_at'     => $export->created_at?->toIso8601String(),
            'finished_at'    => $export->finished_at?->toIso8601String(),
            'expires_at'     => $export->expires_at?->toIso8601String(),
            'download_url'   => $this->pdfExports->downloadUrl($export),
        ]);
    }

    private function isSuperadmin(Request $request): bool
    {
        /** @var array{permissions?: list<string>}|null $payload */
        $payload = $request->attributes->get('jwt');

        return in_array('superadmin.all', $payload['permissions'] ?? [], true);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
