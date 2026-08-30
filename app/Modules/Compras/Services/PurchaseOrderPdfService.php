<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * REQ-136/149 — PDF real de la orden de compra (2 versiones: con/sin costo). Generado
 * server-side con dompdf (barryvdh/laravel-dompdf) — pedido explícito de Luis (daily sprint2,
 * 2026-07-14): el patrón `window.print()` que ya usa Cotizaciones (ver QuoteDocument.tsx) no
 * alcanza acá porque el PDF también se adjunta a un correo real (necesita el archivo generado en
 * el servidor, no solo renderizarse en el navegador de quien lo pide).
 */
final class PurchaseOrderPdfService
{
    private const STATUS_LABELS = [
        PurchaseOrder::STATUS_POR_APROBAR       => 'Por aprobar',
        PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR => 'Pendiente por liquidar',
        PurchaseOrder::STATUS_ORDENADO           => 'Ordenado',
        PurchaseOrder::STATUS_EN_TRANSITO        => 'En tránsito',
        PurchaseOrder::STATUS_EN_ADUANA          => 'En aduana',
        PurchaseOrder::STATUS_EN_TRANSITO_LOCAL  => 'En tránsito local',
        PurchaseOrder::STATUS_RECIBIDO           => 'Recibido',
    ];

    private const SHIPPING_LABELS = [
        PurchaseOrder::SHIPPING_AEREO      => 'Aéreo',
        PurchaseOrder::SHIPPING_MARITIMO   => 'Marítimo',
        PurchaseOrder::SHIPPING_TERRESTRE  => 'Terrestre',
    ];

    /** REQ-136 Escenario 1: "sin costo" no muestra ningún precio ni monto total, solo productos y cantidades. */
    public function generate(PurchaseOrder $order, bool $includeCost): PdfDocument
    {
        $order->loadMissing(['provider', 'lines.catalogProduct']);

        return Pdf::loadView('compras.purchase-order-pdf', [
            'order'              => $order,
            'includeCost'        => $includeCost,
            'statusLabel'        => self::STATUS_LABELS[$order->status] ?? $order->status,
            'shippingTypeLabel'  => $order->shipping_type !== null
                ? (self::SHIPPING_LABELS[$order->shipping_type] ?? $order->shipping_type)
                : 'Sin especificar',
        ])->setPaper('letter');
    }
}
