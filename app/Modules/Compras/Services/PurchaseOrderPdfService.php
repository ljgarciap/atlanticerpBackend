<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Support\TranslationException;
use App\Shared\Support\PdfLogo;
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
    public const LANG_ES = 'es';
    public const LANG_EN = 'en';

    public function __construct(private readonly PurchaseOrderTranslationService $translations)
    {
    }

    /**
     * REQ-136 Escenario 1: "sin costo" no muestra ningún precio ni monto total, solo productos y cantidades.
     *
     * SCRUM-1127 — `$lang`: `es` (default, exactamente el documento de siempre) o `en`, que se renderiza desde la traducción GUARDADA de la
     * orden (nunca llama a la IA). Los textos fijos salen de `lang/{es,en}/purchase_order_pdf.php`.
     *
     * @throws TranslationException si `$lang` es `en` y la orden no tiene versión en inglés vigente
     */
    public function generate(PurchaseOrder $order, bool $includeCost, string $lang = self::LANG_ES): PdfDocument
    {
        $order->loadMissing(['provider', 'lines.catalogProduct']);

        $lang = $lang === self::LANG_EN ? self::LANG_EN : self::LANG_ES;
        $descriptions = $lang === self::LANG_EN ? $this->translations->linesForRender($order) : [];

        $shippingLabel = $order->shipping_type !== null
            ? trans("purchase_order_pdf.shipping.{$order->shipping_type}", [], $lang)
            : trans('purchase_order_pdf.unspecified', [], $lang);

        // SCRUM-886/893 — isPhpEnabled requerido para el pie de página real (page_text(), ver
        // `pdf.partials.footer`), mismo mecanismo que ServiceQuotePdfService/QuotePdfService.
        return Pdf::setOption('isPhpEnabled', true)
            ->loadView('compras.purchase-order-pdf', [
                'order'              => $order,
                'includeCost'        => $includeCost,
                'lang'               => $lang,
                'descriptions'       => $descriptions,
                'statusLabel'        => trans("purchase_order_pdf.statuses.{$order->status}", [], $lang),
                'shippingTypeLabel'  => $shippingLabel,
                'pageLabel'          => trans('purchase_order_pdf.page', [], $lang),
                'logoDataUri'        => PdfLogo::dataUri(),
            ])->setPaper('letter');
    }
}
