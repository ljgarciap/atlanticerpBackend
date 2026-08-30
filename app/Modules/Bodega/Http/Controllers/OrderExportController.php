<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\OrderExportService;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SCRUM-390 (REQ-320, RN1) — descarga a Excel de la Hoja de Picking y de la tabla de detalle de
 * artículos, disponible en ambos lugares del frontend. Solo lectura, misma data que sus
 * contrapartes JSON — ver docblock de `OrderExportService`.
 */
final class OrderExportController extends Controller
{
    public function __construct(private readonly OrderExportService $export) {}

    /** GET /api/bodega/orders/{id}/picking-sheet/export */
    public function pickingSheet(int $id): JsonResponse|StreamedResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — export real de datos, no un GET del recurso.
        app(AuditService::class)->log(
            action:     AuditService::BODEGA_ORDER_PICKING_SHEET_EXPORTED,
            entityType: 'order',
            entityId:   $order->id,
        );

        return $this->download($this->export->pickingSheetXlsx($order), "hoja-picking-{$order->order_number}.xlsx");
    }

    /** GET /api/bodega/orders/{id}/items/export */
    public function itemDetail(int $id): JsonResponse|StreamedResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — export real de datos, no un GET del recurso.
        app(AuditService::class)->log(
            action:     AuditService::BODEGA_ORDER_ITEM_DETAIL_EXPORTED,
            entityType: 'order',
            entityId:   $order->id,
        );

        return $this->download($this->export->itemDetailXlsx($order), "detalle-pedido-{$order->order_number}.xlsx");
    }

    private function download(string $bytes, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
