<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-844 — @page + .doc-header fijo + footer real vía page_text(), mismo mecanismo que
         quote-pdf.blade.php (único soporte real de paginación de dompdf). Márgenes idénticos a
         Cotización para que ambos documentos compartan posición de header/footer. --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }

    .doc-header { position: fixed; top: -80px; left: 0; right: 0; height: 70px; }
    .doc-header .brand-row { display: table; width: 100%; }
    .doc-header .brand-cell { display: table-cell; vertical-align: middle; }
    .doc-header .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; padding-left: 10px; }
    .doc-header .brand-line { border-bottom: 2px solid #5BA5A0; margin-top: 10px; }

    .title { font-size: 14px; font-weight: bold; margin-top: 0; margin-bottom: 12px; }
    .sub { color: #6b7280; font-size: 10px; margin-bottom: 12px; }

    .card { width: 100%; border: 1px solid #E3F0EE; border-radius: 4px; margin-bottom: 14px; page-break-inside: avoid; }
    .card table.layout { width: 100%; border-collapse: collapse; }
    {{-- SCRUM-847 — recuadro consistente, mismo criterio que quote-pdf.blade.php: max-width igual
         a max-height (antes solo limitaba max-height, ancho variaba con la proporción original) +
         fondo blanco + centrado, para que fotos con canal alfa (SCRUM-830) no muestren hueco. --}}
    .card .photo-cell { width: 32%; padding: 10px; vertical-align: middle; text-align: center; background: #fff; }
    .card .photo-cell img { max-width: 120px; max-height: 120px; }
    .card .photo-placeholder {
        width: 100%; height: 100px; background: #f7f5ef; border: 1px dashed #d1d5db;
        color: #9ca3af; font-size: 9px; text-align: center; vertical-align: middle;
    }
    .card .data-cell { padding: 10px 12px 10px 0; vertical-align: top; }
    .card .reference { font-size: 12px; font-weight: bold; color: #3D7E7A; }
    .card .brand-desc { color: #2a2520; margin: 2px 0 6px 0; }
    .card .price { font-size: 13px; font-weight: bold; color: #9fc54d; margin-bottom: 6px; }

    table.specs { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.specs th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 3px 6px; font-size: 8px; text-transform: uppercase; }
    table.specs td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; font-size: 9px; }

    .empty-note { color: #9ca3af; font-style: italic; margin-top: 20px; }
</style>
</head>
<body>
    {{-- SCRUM-844 — encabezado con logo (antes texto "ATLANTIC"), repetido en cada página vía
         position:fixed, mismo patrón que quote-pdf.blade.php. --}}
    <div class="doc-header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 40px;">
                <img src="{{ $logoDataUri }}" width="36" height="36" alt="Atlantic">
            </div>
            <div class="brand-cell brand-name">ATLANTIC</div>
        </div>
        <div class="brand-line"></div>
    </div>

    <div class="title">Catálogo de productos</div>
    <div class="sub">Catálogo — generado el {{ $generatedAt->format('d/m/Y') }}@if(!empty($volumeLabel)) — {{ $volumeLabel }}@endif</div>

    @forelse($products as $product)
        <div class="card">
            <table class="layout">
                <tr>
                    <td class="photo-cell">
                        @if(!empty($product['photo_url']))
                            <img src="{{ $product['photo_url'] }}" alt="{{ $product['reference'] }}">
                        @else
                            <table class="photo-placeholder" width="100%"><tr><td>Sin imagen</td></tr></table>
                        @endif
                    </td>
                    <td class="data-cell">
                        <div class="reference">{{ $product['reference'] }}</div>
                        <div class="brand-desc">
                            @if(!empty($product['brand']))
                                {{ $product['brand'] }} —
                            @endif
                            {{ $product['name'] }}
                        </div>
                        <div class="price">${{ number_format($product['price_full'], 2) }}</div>

                        @if(!empty($product['specs']))
                            <table class="specs">
                                <thead>
                                    <tr><th>Especificación</th><th>Valor</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($product['specs'] as $spec)
                                        <tr>
                                            <td>{{ $spec['label'] }}</td>
                                            <td>{{ $spec['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    @empty
        <p class="empty-note">No hay productos para incluir en este catálogo.</p>
    @endforelse

    {{-- SCRUM-844 — pie de página REAL (antes un <div> inline, dependiente del contenido) vía
         page_text() de dompdf, mismo mecanismo que quote-pdf.blade.php §16/§17. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $footerText = "Atlantic - Panama - Documento generado automaticamente desde el Catalogo - precios sujetos a cambio sin previo aviso.";
            $w1 = $fontMetrics->getTextWidth($footerText, $font, 7);
            $pdf->page_text((612 - $w1) / 2, 762, $footerText, $font, 7, array(0.55, 0.55, 0.55));

            $pageText = "Pagina {PAGE_NUM} de {PAGE_COUNT}";
            $w2 = $fontMetrics->getTextWidth("Pagina 00 de 00", $font, 7);
            $pdf->page_text((612 - $w2) / 2, 774, $pageText, $font, 7, array(0.55, 0.55, 0.55));
        }
    </script>
</body>
</html>
