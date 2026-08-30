<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- Lote 4 (SCRUM-395, rebote 2026-08-16) — rediseño contra mockup: mismo patrón visual ya
         validado con el cliente en `estatus-pedido-pdf.blade.php` (SCRUM-411/REQ-341) — ícono +
         ATLANTIC + "Powered by AtlanticERP", título grande, datos generales en 2 columnas por
         posición absoluta, pie de página institucional. Agrega sección "Datos de entrega" (RN1:
         nunca precios, ya respetado abajo) para firma física al imprimir — no existía antes. --}}

    {{-- SCRUM-395 (rebote 2026-08-17→18, alcance acotado a impresión multi-página — RESTO del
         requerimiento sin tocar): el margin-top/bottom de @page reserva el espacio para el
         encabezado (`.doc-header`, position:fixed+top, se repite en cada página) y para el pie de
         página (dibujado con `page_text()`, ver `<script type="text/php">` al final del body — NO
         position:fixed+bottom, ese mecanismo es un bug conocido de dompdf que agrega páginas en
         blanco). Antes de este fix, el encabezado/pie de página eran contenido normal del flujo:
         solo aparecían una vez (el pie quedaba pegado después de la firma en vez de comportarse
         como footer real). --}}

    {{-- SCRUM-783 (rebote Daniela 2026-08-20): `.meta-block` usaba position:absolute para las 2
         columnas + un min-height fijo adivinado (110px) — los hijos absolutos no aportan altura al
         contenedor, así que .meta-block quedaba SIEMPRE en 110px sin importar cuántas líneas
         tuviera cada columna. La columna izquierda (6 líneas: Cliente/Proyecto/Dirección/
         Contacto/Teléfono/Correo) desbordaba ese alto y se montaba sobre la tabla siguiente
         ("Correo" pisando el header "REF. PÚBLICA"). Fix: display:table/table-cell (mismo patrón
         ya probado en `ventas-diseno/quote-pdf.blade.php`, §6 de SCRUM-766) — el alto del bloque
         crece solo con la columna más alta, sin adivinar un número fijo. El offset visual original
         (columna izquierda arranca más abajo que la derecha, alineada bajo el título) se preserva
         con padding-top en vez de top absoluto. --}}
    @page { margin: 70px 40px 45px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }

    .doc-header { position: fixed; top: -55px; left: 0; right: 0; height: 34px; }

    /* Marca (ícono + ATLANTIC + Powered by AtlanticERP) — igual a estatus-pedido-pdf.blade.php */
    .brand-row { position: relative; height: 30px; margin-bottom: 4px; }
    .brand-icon { position: absolute; left: 0; top: 2px; width: 22px; height: 22px; }
    .brand-icon span { position: absolute; width: 14px; height: 14px; border-radius: 50%; opacity: 0.85; }
    .brand-icon .c1 { background: #5BA5A0; left: 0; top: 4px; }
    .brand-icon .c2 { background: #9fc54d; left: 8px; top: 0; }
    .brand-icon .c3 { background: #3D7E7A; left: 8px; top: 8px; }
    .brand-text { margin-left: 28px; }
    .brand-name { font-size: 18px; font-weight: bold; color: #5BA5A0; line-height: 1.1; }
    .brand-sub { color: #6b7280; font-size: 9px; }

    .doc-title { font-size: 20px; font-weight: bold; color: #5BA5A0; margin-top: 14px; }

    /* Bloque de datos generales — izq. datos de cliente, der. datos de guía/logística. Alto
       dinámico (display:table), no min-height fijo — ver nota SCRUM-783 arriba del bloque HTML. */
    .meta-block { display: table; width: 100%; margin: 14px 0 18px; border-bottom: 3px solid #5BA5A0; padding-bottom: 12px; }
    .meta-left { display: table-cell; width: 52%; vertical-align: top; padding-top: 34px; }
    .meta-right { display: table-cell; width: 48%; vertical-align: top; text-align: left; }
    .meta-line { margin-bottom: 6px; font-size: 10.5px; }
    .meta-line .value { font-weight: bold; }
    .meta-gap { height: 10px; }

    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; }
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
    .no-prices { color: #9ca3af; font-size: 9px; font-style: italic; margin-top: 6px; }

    .status-line { margin-top: 16px; font-size: 10.5px; }

    /* "Datos de entrega" — bloque de firma física para imprimir, líneas en blanco.
       page-break-inside:avoid (SCRUM-395, rebote 2026-08-17) — el bloque completo (título +
       las 2 líneas de firma) pasa entero a la página siguiente si no entra completo en la
       actual, nunca deja la línea de firma sola separada del resto. */
    .delivery-data { margin-top: 30px; border-top: 3px solid #5BA5A0; padding-top: 10px; page-break-inside: avoid; }
    .delivery-data .title { color: #3D7E7A; font-size: 10px; font-weight: bold; text-transform: uppercase; margin-bottom: 14px; }
    .delivery-data .line { width: 260px; margin-left: auto; text-align: right; margin-bottom: 16px; }
    .delivery-data .line .fill { border-bottom: 1px solid #2a2520; height: 18px; }
    .delivery-data .line .caption { color: #9ca3af; font-size: 8.5px; margin-top: 2px; }

</style>
</head>
<body>
    @php
        $primaryContact = $order->pipelineCard?->contacts?->first();
        $guideNumber = $order->order_number.'-G';
    @endphp

    {{-- Encabezado principal: primer hijo de <body>, position:fixed — dompdf lo repite en
         todas las páginas (ver @page/.doc-header arriba). --}}
    <div class="doc-header">
        <div class="brand-row">
            <div class="brand-icon">
                <span class="c1"></span><span class="c2"></span><span class="c3"></span>
            </div>
            <div class="brand-text">
                <div class="brand-name">ATLANTIC</div>
                <div class="brand-sub">Powered by AtlanticERP</div>
            </div>
        </div>
    </div>

    <div class="doc-title">GUÍA DE ENTREGA</div>

    <div class="meta-block">
        {{-- Columna izquierda — datos del cliente, a la altura del título. Va primera en el DOM
             a propósito: con display:table-cell el orden visual sigue al orden del DOM (a
             diferencia del position:absolute anterior, que podía invertirlo con left/right). --}}
        <div class="meta-left">
            <div class="meta-line">Cliente: <span class="value">{{ $order->customer_name ?? '—' }}</span></div>
            <div class="meta-line">Proyecto: {{ $order->pipelineCard?->salesProject?->name ?? '—' }}</div>
            <div class="meta-line">Dirección de entrega: {{ $order->delivery_address ?? '—' }}</div>
            <div class="meta-line">Contacto: {{ $primaryContact?->name ?? '—' }}</div>
            <div class="meta-line">Teléfono: {{ $primaryContact?->phone ?? '—' }}</div>
            <div class="meta-line">Correo: {{ $primaryContact?->email ?? '—' }}</div>
        </div>
        {{-- Columna derecha — guía/pedido/logística, a la altura del logo --}}
        <div class="meta-right">
            <div class="meta-line">Guía n°: <span class="value">#{{ $guideNumber }}</span></div>
            <div class="meta-line">Pedido #{{ $order->order_number }}</div>
            <div class="meta-line">Fecha: <span class="value">{{ now()->format('d/m/Y, g:i a') }}</span></div>
            <div class="meta-line">Vendedor: <span class="value">{{ trim(($order->pipelineCard?->owner?->first_name ?? '').' '.($order->pipelineCard?->owner?->last_name ?? '')) ?: '—' }}</span></div>
            <div class="meta-line">Repartidor: <span class="value">{{ trim(($order->courier?->first_name ?? '').' '.($order->courier?->last_name ?? '')) ?: '—' }}</span></div>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Ref. pública</th>
                <th>Artículo</th>
                <th>Cantidad</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->catalogProduct?->reference ?? $item->reference_snapshot ?? '—' }}</td>
                    <td>{{ $item->description_snapshot ?? $item->catalogProduct?->name ?? '—' }}</td>
                    <td>{{ $item->qty_delivered > 0 ? $item->qty_delivered : $item->qty_requested }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    {{-- RN1 — este documento nunca incluye precios. --}}
    <p class="no-prices">Este documento no incluye precios.</p>

    <p class="status-line"><strong>Estado de la entrega:</strong> {{ $order->stage }}</p>

    <div class="delivery-data">
        <div class="title">Datos de entrega</div>
        <div class="line">
            <div class="fill"></div>
            <div class="caption">Recibido por — Nombre y cédula/identificación</div>
        </div>
        <div class="line">
            <div class="fill"></div>
            <div class="caption">Firma y fecha/hora</div>
        </div>
    </div>

    {{-- SCRUM-395 (rebote 2026-08-18) — pie de página vía page_text(), NO position:fixed+bottom:
         ese mecanismo es un bug conocido de dompdf que agrega páginas en blanco. page_text() dibuja
         directamente sobre el canvas de cada página ya renderizada, sin afectar el cálculo de
         layout/paginación — mismo mecanismo que `ventas-diseno/quote-pdf.blade.php` (SCRUM-766). --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $footerText = "Atlantic · Panamá · Powered by AtlanticERP";
            $w = $fontMetrics->getTextWidth($footerText, $font, 8.5);
            $pdf->page_text((612 - $w) / 2, 770, $footerText, $font, 8.5, array(0.61, 0.64, 0.69));
        }
    </script>
</body>
</html>
