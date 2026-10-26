<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-824 — unificación visual con ventas-diseno/quote-pdf.blade.php (SCRUM-801/766).
         @page/header fijo/footer real/bloque final indivisible calcados 1:1 de esa plantilla —
         ver los comentarios ahí para el porqué de cada regla, acá solo lo específico de Servicios
         (contenido propio: encabezado de 2 líneas en vez de badge, columnas de tabla propias,
         Descuento siempre visible en vez de condicional). --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }

    .doc-header { position: fixed; top: -80px; left: 0; right: 0; height: 70px; }
    .doc-header .brand-row { display: table; width: 100%; }
    .doc-header .brand-cell { display: table-cell; vertical-align: middle; }
    .doc-header .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; padding-left: 10px; }
    .doc-header .brand-line { border-bottom: 2px solid #5BA5A0; margin-top: 10px; }

    {{-- Encabezado secundario — mismo tratamiento tipográfico que el badge de V&D, pero el
         contenido propio de Servicios (número de cotización+ticket, fecha de generación) son 2
         líneas en vez de 1 palabra — el ticket pide igualar estilo, nunca el texto/contenido. --}}
    .secondary-header-row { margin-bottom: 10px; }
    .secondary-header-badge { display: block; color: #3D7E7A; font-weight: bold; font-size: 14px; line-height: 17px; }
    .secondary-header-sub { color: #6b7280; font-size: 10px; line-height: 13px; margin-top: 2px; }
    .divider { border-bottom: 1px solid #d1d5db; margin: 10px 0; }

    .info-row { display: table; width: 100%; margin-bottom: 12px; }
    .info-col { display: table-cell; width: 50%; vertical-align: top; font-size: 11px; line-height: 16px; }
    .info-label { font-weight: bold; }

    table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 5px; font-size: 8px; line-height: 10px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td { padding: 4px 5px; border-bottom: 1px solid #f0f0f0; font-size: 10px; line-height: 13px; vertical-align: top; }

    .empty-note { color: #9ca3af; font-style: italic; font-size: 10px; }

    .bottom-top { margin-top: 24px; }
    .block-title { font-size: 9px; line-height: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; margin-top: 12px; }
    .block-title:first-child { margin-top: 0; }
    .block-value { font-size: 10px; line-height: 13px; white-space: pre-line; }

    .bottom-block-spacer { height: 0; }
    .force-new-page { page-break-before: always; }
    .final-block { page-break-inside: avoid; }

    .bottom-condiciones-row { display: table; width: 100%; margin-top: 12px; }
    .bottom-col { display: table-cell; width: 50%; vertical-align: top; }
    .bottom-col.left { padding-right: 16px; }
    .bottom-col.right { padding-left: 16px; vertical-align: bottom; }

    .totals-signature { display: table; margin: 0 0 0 auto; }
    table.totals { width: auto; margin: 0 0 0 auto; border-collapse: collapse; border-spacing: 0; }
    table.totals td { padding: 2px 0; font-size: 11px; }
    table.totals td:first-child { padding-right: 18px; }
    table.totals td.numeric { text-align: right; }
    table.totals tr.grand-total td { font-size: 13px; font-weight: bold; border-top: 1px solid #2a2520; padding-top: 4px; }

    .signature { width: auto; margin: 48px 0 0 auto; padding-top: 4px; border-top: 1px solid #9ca3af; text-align: center; font-size: 9px; color: #6b7280; white-space: nowrap; }
    .signature .signed-name { font-size: 15px; color: #2a2520; margin-bottom: 2px; }
</style>
</head>
<body>
    <div class="doc-header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 40px;">
                <img src="{{ $logoDataUri }}" width="36" height="36" alt="Atlantic">
            </div>
            <div class="brand-cell brand-name">ATLANTIC</div>
        </div>
        <div class="brand-line"></div>
    </div>

    <div class="secondary-header-row">
        <div class="secondary-header-badge">Cotización de Servicio {{ $quote->numero }} — Ticket {{ $ticket->numero }}</div>
        <div class="secondary-header-sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>
    <div class="divider"></div>

    <div class="info-row">
        <div class="info-col">
            <div>
                <span class="info-label">Cliente:</span>
                @if(($detail['cliente_master'] ?? null) === ($detail['subcliente'] ?? null))
                    {{ $detail['cliente_master'] ?? '—' }}
                @else
                    {{ $detail['cliente_master'] ?? '—' }} — {{ $detail['subcliente'] ?? '—' }}
                @endif
            </div>
            <div>
                <span class="info-label">Contacto:</span>
                {{ $detail['contacto'] ?? '—' }}{{ !empty($detail['telefono']) ? ' · '.$detail['telefono'] : '' }}
            </div>
            <div><span class="info-label">Dirección:</span> {{ $detail['direccion'] ?? '—' }}</div>
        </div>
        <div class="info-col">
            <div><span class="info-label">Proyecto asociado:</span> {{ $detail['proyecto'] ?? 'Sin proyecto asociado' }}</div>
        </div>
    </div>

    <table class="items">
        <colgroup>
            <col style="width: 52%"><col style="width: 16%"><col style="width: 16%"><col style="width: 16%">
        </colgroup>
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="numeric">Cantidad</th>
                <th class="numeric">Precio unitario</th>
                <th class="numeric">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="numeric">{{ rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.') }}</td>
                    <td class="numeric">${{ number_format($item['unit_price'], 2) }}</td>
                    <td class="numeric">${{ number_format($item['subtotal'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty-note">Sin ítems registrados</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- SCRUM-824 — bloque final indivisible, pegado al pie de página, mismo mecanismo que
         SCRUM-801 (ver ServiceQuotePdfLayoutEstimator + comentarios en quote-pdf.blade.php). --}}
    <div class="bottom-block-spacer{{ $bottomBlockForceNewPage ? ' force-new-page' : '' }}" style="height: {{ $bottomBlockSpacerPt }}pt;"></div>
    <div class="final-block">
        <div class="bottom-top">
            <div class="block-title">Observaciones</div>
            <div class="block-value">{{ $quote->observations ?: '—' }}</div>

            @if(!empty($paymentLines))
            <div class="block-title">Información de pago</div>
            <div class="block-value">{!! implode('<br>', array_map('e', $paymentLines)) !!}</div>
            @endif
        </div>
        <div class="bottom-condiciones-row">
            <div class="bottom-col left">
                <div class="block-title">Condiciones del servicio</div>
                <div class="block-value">{{ $conditions }}</div>
            </div>
            <div class="bottom-col right">
                <div class="totals-signature">
                    <table class="totals">
                        <tr>
                            <td>Subtotal</td>
                            <td class="numeric">${{ number_format($quote->subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Descuento ({{ number_format($quote->discount_percent, 2) }}%)</td>
                            <td class="numeric">
                                @if($quote->discount_percent > 0)
                                    -${{ number_format($quote->subtotal * $quote->discount_percent / 100, 2) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>ITBMS</td>
                            <td class="numeric">${{ number_format($quote->itbms, 2) }}</td>
                        </tr>
                        <tr class="grand-total">
                            <td>Total</td>
                            <td class="numeric">${{ number_format($quote->total, 2) }}</td>
                        </tr>
                    </table>
                    <div class="signature">
                        @if($firmada)
                            <div class="signed-name">{{ $detail['contacto'] ?? ($detail['cliente_master'] ?? 'Cliente') }}</div>
                        @endif
                        Firma del cliente
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Pie de página real (SCRUM-824 §7) — reemplaza el <div class="footer"> viejo (contenido
         normal del body, se desplazaba con el contenido). Mismo mecanismo/texto que V&D. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $companyText = "{!! $footerCompanyText ?? \App\Modules\AdminCont\Support\PdfFooterText::defaultFooter() !!}";
            $w1 = $fontMetrics->getTextWidth($companyText, $font, 7);
            $pdf->page_text((612 - $w1) / 2, 762, $companyText, $font, 7, array(0.55, 0.55, 0.55));

            $pageText = "Pagina {PAGE_NUM} de {PAGE_COUNT}";
            $w2 = $fontMetrics->getTextWidth("Pagina 00 de 00", $font, 7);
            $pdf->page_text((612 - $w2) / 2, 774, $pageText, $font, 7, array(0.55, 0.55, 0.55));
        }
    </script>
</body>
</html>
