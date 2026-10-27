<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }

    /* SCRUM-766 §4/§17 — encabezado principal repetido en TODAS las páginas (dompdf repite todo
       elemento position:fixed) — el margin-top de @page arriba ya reserva el espacio. */
    .doc-header { position: fixed; top: -80px; left: 0; right: 0; height: 70px; }
    .doc-header .brand-row { display: table; width: 100%; }
    .doc-header .brand-cell { display: table-cell; vertical-align: middle; }
    .doc-header .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; padding-left: 10px; }
    .doc-header .brand-line { border-bottom: 2px solid #5BA5A0; margin-top: 10px; }

    {{-- SCRUM-766 §4 — encabezado secundario = campo Descripción de la cotización (el mockup
         mostraba un valor de EJEMPLO ahí, no texto fijo — rebote 2026-08-20: había quedado
         hardcodeado literal). Solo página 1 (flujo normal, no fixed). Blade comment (no CSS
         /* */) a propósito, para que no termine filtrado dentro del <style> del HTML/PDF final. --}}
    .secondary-header-row { margin-bottom: 10px; }
    .secondary-header-badge { display: inline-block; color: #3D7E7A; font-weight: bold; font-size: 14px; text-transform: uppercase; }
    .divider { border-bottom: 1px solid #d1d5db; margin: 10px 0; }

    /* SCRUM-766 §6 — Cliente (izq) / Proyecto-Cotización (der), 2 columnas ~50/50.
       SCRUM-801 — 1 espacio vertical antes de Partidas (convención de esta plantilla: "1 espacio"
       ≈ 12px, "2 espacios" ≈ 24px, sin recuadro/línea de referencia real disponible). */
    .info-row { display: table; width: 100%; margin-bottom: 12px; }
    .info-col { display: table-cell; width: 50%; vertical-align: top; font-size: 11px; line-height: 16px; }
    .info-col.right { text-align: right; }
    .info-label { font-weight: bold; }

    /* SCRUM-766 §7/§8 — tabla de productos. Sin margin-top propio (SCRUM-801): el espacio hacia
       Partidas ya lo da .info-row margin-bottom, para no duplicar el espaciado.
       line-height explícito en th/td (SCRUM-801 3er rebote) — QuotePdfLayoutEstimator necesita un
       valor fijo y conocido para simular la altura de cada fila; sin esto dependía del "normal"
       default de dompdf, no reproducible desde PHP. */
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 5px; font-size: 8px; line-height: 10px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td { padding: 4px 5px; border-bottom: 1px solid #f0f0f0; font-size: 10px; line-height: 13px; vertical-align: top; }
    /* SCRUM-847 — recuadro consistente: antes solo limitaba max-height, así que el ancho
       renderizado variaba según la proporción original de cada foto ("algunas cuadradas, otras
       rectangulares"). max-width/max-height iguales (42px, el mismo valor ya usado por
       QuotePdfLayoutEstimator::itemRowHeight() — no tocar sin actualizar esa clase) + fondo blanco
       en la celda para que las fotos con canal alfa (SCRUM-830) no muestren hueco. */
    table.items td.photo-cell { background: #fff; text-align: center; vertical-align: middle; }
    table.items td.photo-cell img { max-width: 42px; max-height: 42px; }
    table.items td.photo-cell .no-photo { color: #cbd5e1; font-size: 8px; }

    /* SCRUM-766 §9 — fila de partida: fondo gris claro, negrita, ancho completo. */
    tr.part-header td { background: #f3f4f6; font-weight: bold; padding: 6px 5px; font-size: 10.5px; line-height: 13px; }
    /* SCRUM-766 §10 — total de partida, alineado a la derecha, separado antes de la siguiente sección. */
    tr.part-total td { text-align: right; font-weight: bold; font-size: 10.5px; line-height: 13px; padding: 4px 5px 10px 5px; border-bottom: none; }

    /* SCRUM-766 §11/§12 — Observaciones + Instrucciones de pago, ancho completo (columna
       izquierda solamente — no hay nada que alinear a la derecha todavía acá). */
    .bottom-top { margin-top: 24px; }
    .block-title { font-size: 9px; line-height: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; margin-top: 12px; }
    .block-title:first-child { margin-top: 0; }
    .block-value { font-size: 10px; line-height: 13px; white-space: pre-line; }

    /* SCRUM-801 (3er rebote, 2026-09-01) — Observaciones→Firma tratado como un único bloque
       indivisible, pegado al pie de la página que lo contiene. `page-break-inside: avoid` es la
       garantía DURA (nunca se parte, con o sin acierto de la estimación de PHP); el espaciador
       `.bottom-block-spacer` (altura calculada por QuotePdfLayoutEstimator, ver ese docblock) es
       puramente cosmético — empuja el bloque hacia el pie cuando sobra espacio. Ver también
       `.force-new-page` más abajo para el Escenario 3 del ticket (bloque no entra en lo que queda
       de la página actual → se fuerza página nueva completa para él). */
    .bottom-block-spacer { height: 0; }
    .force-new-page { page-break-before: always; }
    .final-block { page-break-inside: avoid; }

    /* SCRUM-986 — Condiciones ya no comparte fila con Totales/Firma (esos subieron a
       .sig-totals-block, justo bajo el último ítem). Se conserva el ancho de media página (50%) que
       tenía su columna para no alterar los saltos de línea del texto de Condiciones. */
    .bottom-condiciones-row { margin-top: 12px; }
    .conditions-col { width: 50%; padding-right: 16px; }

    /* SCRUM-986 — Firma (izq) | Totales (der): DOS bloques independientes, mismo alto de arranque
       (ambas celdas vertical-align:top), inmediatamente tras el último ítem de la última partida.
       Indivisible: page-break-inside:avoid en el envoltorio — si no cabe en lo que queda de la
       página pasa íntegro a la siguiente (nunca partido). display:table/table-cell, no flex (dompdf
       rompe la paginación con flex, ver rebote SCRUM-801 2026-08-31). */
    .sig-totals-block { page-break-inside: avoid; margin-top: 12px; }
    .sig-totals-row { display: table; width: 100%; }
    .sig-totals-col { display: table-cell; width: 50%; vertical-align: top; }
    .sig-totals-col.left { padding-right: 16px; }
    .sig-totals-col.right { padding-left: 16px; }

    /* SCRUM-766 §13 — bloque de totales SIN recuadro completo, solo las líneas necesarias;
       compacto y alineado a la derecha de su columna (SCRUM-801). */
    table.totals { width: auto; margin: 0 0 0 auto; border-collapse: collapse; border-spacing: 0; }
    table.totals td { padding: 2px 0; font-size: 11px; }
    table.totals td:first-child { padding-right: 18px; }
    table.totals td.numeric { text-align: right; }
    table.totals tr.grand-total td { font-size: 13px; font-weight: bold; border-top: 1px solid #2a2520; padding-top: 4px; }

    /* SCRUM-766 §14 / SCRUM-986 — firma: bloque propio en la columna izquierda. El margen superior
       deja lugar físico para firmar (con $signed lo ocupa el nombre del firmante). */
    .signature { width: 70%; margin: 28px 0 0 0; padding-top: 4px; border-top: 1px solid #9ca3af; text-align: center; font-size: 9px; color: #6b7280; white-space: nowrap; }
    /* Nombre largo del firmante: wrap dentro de la columna (el nowrap del bloque lo sacaba del margen y
       empujaba los Totales fuera de la hoja — Pre-QA SCRUM-986). */
    .signature .signed-name { font-size: 15px; color: #2a2520; margin-bottom: 2px; white-space: normal; word-wrap: break-word; }

    .empty-note { color: #9ca3af; font-style: italic; font-size: 10px; }
</style>
</head>
<body>
    {{-- §4 — encabezado principal, se repite en cada página vía position:fixed. Logo vía
         data URI (no <svg> inline — dompdf no lo renderiza en este entorno, ver PdfLogo). --}}
    <div class="doc-header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 40px;">
                <img src="{{ $logoDataUri }}" width="36" height="36" alt="Atlantic">
            </div>
            <div class="brand-cell brand-name">ATLANTIC</div>
        </div>
        <div class="brand-line"></div>
    </div>

    {{-- §4 — encabezado secundario, solo página 1 (flujo normal): campo Descripción real de la
         cotización, con el tratamiento visual del mockup. --}}
    <div class="secondary-header-row">
        <div class="secondary-header-badge">{{ $quote['description'] ?: '—' }}</div>
    </div>
    <div class="divider"></div>

    {{-- §6 — Cliente/Subcliente/RUC/Contacto (izq) + Proyecto/Cotización/Fecha/Diseñador (der). --}}
    <div class="info-row">
        <div class="info-col">
            <div><span class="info-label">Cliente:</span> {{ $quote['master_client']['name'] ?? '—' }}</div>
            <div><span class="info-label">Subcliente:</span> {{ $quote['sub_client']['business_name'] ?? '—' }}</div>
            <div><span class="info-label">RUC:</span> {{ ($quote['sub_client']['receptor_tipo'] ?? null) === '02' ? 'Consumidor final' : (filled($quote['ruc'] ?? null) ? $quote['ruc'] : '—') }}</div>
            @forelse($quote['contacts'] as $contact)
                <div>
                    <span class="info-label">Contacto:</span> {{ $contact['name'] }}
                    @if($contact['phone']) · {{ $contact['phone'] }} @endif
                    @if($contact['email']) · {{ $contact['email'] }} @endif
                </div>
            @empty
                <div class="empty-note">Sin contactos</div>
            @endforelse
        </div>
        <div class="info-col right">
            <div>Proyecto: {{ $quote['sales_project']['name'] ?? '—' }}</div>
            <div>Cotización n°: {{ $quote['folio'] ?? '—' }}</div>
            <div>Fecha: {{ $quote['generated_at'] ? \Illuminate\Support\Carbon::parse($quote['generated_at'])->translatedFormat('d F Y') : '—' }}</div>
            <div>
                {{-- SCRUM-1195 — el Responsable del proyecto (`designer`); `owner` es quien generó la versión y no va en el documento del cliente. --}}
                Diseñador: {{ ($quote['designer'] ?? $quote['owner'])['name'] }}
                @if(($quote['designer'] ?? $quote['owner'])['phone'] ?? null) · {{ ($quote['designer'] ?? $quote['owner'])['phone'] }} @endif
            </div>
        </div>
    </div>

    {{-- §7/§8 — tabla de productos. Anchos: DESCRIPCIÓN siempre la mayor (§8). --}}
    <table class="items">
        <colgroup>
            @if($external)
                <col style="width: 12%"><col style="width: 14%"><col style="width: 58%"><col style="width: 16%">
            @else
                <col style="width: 10%"><col style="width: 9%"><col style="width: 33%">
                <col style="width: 6%"><col style="width: 12%"><col style="width: 8%"><col style="width: 12%">
            @endif
        </colgroup>
        <thead>
            <tr>
                <th>Foto</th>
                <th>Ref</th>
                <th>Descripción</th>
                <th class="numeric">Ud</th>
                @if(!$external)
                    <th class="numeric">Precio/Ud</th>
                    <th class="numeric">Dto</th>
                    <th class="numeric">Total</th>
                @endif
            </tr>
        </thead>
        @forelse($quote['parts'] as $part)
            <tbody>
                <tr class="part-header">
                    <td colspan="{{ $external ? 4 : 7 }}">{{ $part['name'] }}</td>
                </tr>
                @forelse($part['items'] as $item)
                    <tr>
                        <td class="photo-cell">
                            @if(!empty($item['photo_url']))
                                <img src="{{ $item['photo_url'] }}" alt="">
                            @else
                                <span class="no-photo">Sin foto</span>
                            @endif
                        </td>
                        <td>{{ $item['reference'] }}</td>
                        <td>{{ $item['description'] }}</td>
                        <td class="numeric">{{ rtrim(rtrim(number_format($item['quantity'], 2, '.', ''), '0'), '.') }}</td>
                        @if(!$external)
                            <td class="numeric">${{ number_format($item['effective_unit_price'], 2) }}</td>
                            <td class="numeric">{{ number_format($item['discount_percent'] ?? 0, 0) }}%</td>
                            <td class="numeric">${{ number_format($item['line_total'], 2) }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $external ? 4 : 7 }}" class="empty-note">Sin ítems</td></tr>
                @endforelse
                {{-- §10 — Total partida, solo interna (política SCRUM-140: nunca cifras en externa). --}}
                @if(!$external)
                    <tr class="part-total">
                        <td colspan="7">Total partida: ${{ number_format($part['subtotal'], 2) }}</td>
                    </tr>
                @endif
            </tbody>
        @empty
            <tbody>
                <tr><td colspan="{{ $external ? 4 : 7 }}" class="empty-note">Sin partidas registradas</td></tr>
            </tbody>
        @endforelse
    </table>

    {{-- SCRUM-986 — Firma (izq) | Totales (der), inmediatamente bajo el último ítem de la última
         partida, como dos bloques independientes. Solo interna (política SCRUM-140: la externa
         nunca muestra totales ni firma, sin cambios). Indivisible vía .sig-totals-block. --}}
    @if(!$external)
        <div class="sig-totals-block">
            <div class="sig-totals-row">
                <div class="sig-totals-col left">
                    <div class="signature">
                        @if($signed && $signerName)
                            <div class="signed-name">{{ $signerName }}</div>
                        @endif
                        Firma del cliente
                    </div>
                </div>
                <div class="sig-totals-col right">
                    <table class="totals">
                        <tr>
                            <td>Subtotal</td>
                            <td class="numeric">${{ number_format($quote['subtotal'], 2) }}</td>
                        </tr>
                        @if($quote['discount_totals_amount'] > 0)
                            <tr>
                                <td>Descuento</td>
                                <td class="numeric">-${{ number_format($quote['discount_totals_amount'], 2) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td>{{ \App\Shared\Multitenancy\TenantRegion::taxLabel() }}{{ ($quote['itbms_exento'] ?? false) ? ' (Exento)' : '' }}</td>
                            <td class="numeric">${{ number_format($quote['itbms'], 2) }}</td>
                        </tr>
                        <tr class="grand-total">
                            <td>Total</td>
                            <td class="numeric">${{ number_format($quote['grand_total'], 2) }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- §11/§12/§13 — Observaciones, Instrucciones de pago y Condiciones, solo interna.
             SCRUM-801 (3er rebote) — envuelto en .final-block (indivisible), con un espaciador
             previo calculado por QuotePdfLayoutEstimator para quedar pegado al pie de la página.
             SCRUM-986 — ahora SIN Totales/Firma (subieron arriba, ver bloque anterior). --}}
        <div class="bottom-block-spacer{{ $bottomBlockForceNewPage ? ' force-new-page' : '' }}" style="height: {{ $bottomBlockSpacerPt }}pt;"></div>
        <div class="final-block">
            <div class="bottom-top">
                <div class="block-title">Observaciones</div>
                <div class="block-value">{{ $quote['observations_preview'] ?: '—' }}</div>

                @if(!empty($paymentLines))
                <div class="block-title">Instrucciones de pago</div>
                <div class="block-value">{!! implode('<br>', array_map('e', $paymentLines)) !!}</div>
                @endif
            </div>
            <div class="bottom-condiciones-row">
                <div class="conditions-col">
                    <div class="block-title">Condiciones</div>
                    <div class="block-value">{{ $quote['conditions_text'] ?: '—' }}</div>
                </div>
            </div>
        </div>
    @endif

    {{-- §16/§17 — pie de página con "Página X de Y" real, repetido en cada página. Único
         mecanismo de dompdf con soporte real de paginación — ver docblock de QuotePdfService. --}}
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
