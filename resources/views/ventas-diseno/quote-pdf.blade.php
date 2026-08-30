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
       Partidas ya lo da .info-row margin-bottom, para no duplicar el espaciado. */
    table.items { width: 100%; border-collapse: collapse; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td { padding: 4px 5px; border-bottom: 1px solid #f0f0f0; font-size: 10px; vertical-align: top; }
    table.items td.photo-cell img { max-width: 100%; max-height: 42px; }
    table.items td.photo-cell .no-photo { color: #cbd5e1; font-size: 8px; }

    /* SCRUM-766 §9 — fila de partida: fondo gris claro, negrita, ancho completo. */
    tr.part-header td { background: #f3f4f6; font-weight: bold; padding: 6px 5px; font-size: 10.5px; }
    /* SCRUM-766 §10 — total de partida, alineado a la derecha, separado antes de la siguiente sección. */
    tr.part-total td { text-align: right; font-weight: bold; font-size: 10.5px; padding: 4px 5px 10px 5px; border-bottom: none; }

    /* SCRUM-766 §11/§12/§13 — sección inferior en 2 columnas: izq info comercial, der resumen.
       SCRUM-801 — 2 espacios verticales (24px) entre la última partida y el bloque que arranca
       con Condiciones (izq). vertical-align:bottom en la columna derecha alinea Totales+Firma con
       la ÚLTIMA línea de Condiciones (no con el tope de la columna izquierda, que empieza más
       arriba con Observaciones/Instrucciones de pago). */
    .bottom-section { display: table; width: 100%; margin-top: 24px; }
    .bottom-col { display: table-cell; width: 50%; vertical-align: top; }
    .bottom-col.left { padding-right: 16px; }
    .bottom-col.right { padding-left: 16px; vertical-align: bottom; }
    .block-title { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 3px; margin-top: 12px; }
    .block-title:first-child { margin-top: 0; }
    .block-value { font-size: 10px; white-space: pre-line; }

    /* SCRUM-766 §13 — bloque de totales SIN recuadro completo, solo las líneas necesarias.
       SCRUM-801 — compacto y alineado a la derecha (width:auto + margin-left:auto, en vez de
       100% de la columna de 50%): ya no se extiende hasta la mitad de la hoja. */
    table.totals { width: auto; margin: 0 0 0 auto; }
    table.totals td { padding: 2px 0; font-size: 11px; }
    table.totals td:first-child { padding-right: 18px; }
    table.totals td.numeric { text-align: right; }
    table.totals tr.grand-total td { font-size: 13px; font-weight: bold; border-top: 1px solid #2a2520; padding-top: 4px; }

    /* SCRUM-766 §14 — firma. SCRUM-801 — 2 espacios verticales (24px) entre Total y Firma. */
    .signature { margin-top: 24px; padding-top: 4px; border-top: 1px solid #9ca3af; text-align: center; font-size: 9px; color: #6b7280; }
    .signature .signed-name { font-size: 15px; color: #2a2520; margin-bottom: 2px; }

    .empty-note { color: #9ca3af; font-style: italic; font-size: 10px; }
</style>
</head>
<body>
    {{-- §4 — encabezado principal, se repite en cada página vía position:fixed. Logo vía
         data URI (no <svg> inline — dompdf no lo renderiza en este entorno, ver PdfLogo). --}}
    <div class="doc-header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 40px;">
                <img src="{{ $logoDataUri }}" width="36" height="36" alt="Illuminations">
            </div>
            <div class="brand-cell brand-name">ILLUMINATIONS</div>
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
            <div><span class="info-label">RUC:</span> {{ $quote['ruc'] ?? '—' }}</div>
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
            <div>Diseñador: {{ $quote['owner']['name'] }}</div>
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

    {{-- §11/§12/§13/§14 — sección inferior, solo interna (política SCRUM-140 sin cambios). --}}
    @if(!$external)
        <div class="bottom-section">
            <div class="bottom-col left">
                <div class="block-title">Observaciones</div>
                <div class="block-value">{{ $quote['observations_preview'] ?: '—' }}</div>

                <div class="block-title">Instrucciones de pago</div>
                <div class="block-value">Cheque a nombre de: Illuminations<br>Pagos ACH: consultar con su diseñador</div>

                <div class="block-title">Condiciones</div>
                <div class="block-value">{{ $quote['conditions_text'] ?: '—' }}</div>
            </div>
            <div class="bottom-col right">
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
                        <td>ITBMS</td>
                        <td class="numeric">${{ number_format($quote['itbms'], 2) }}</td>
                    </tr>
                    <tr class="grand-total">
                        <td>Total</td>
                        <td class="numeric">${{ number_format($quote['grand_total'], 2) }}</td>
                    </tr>
                </table>
                <div class="signature">
                    @if($signed && $signerName)
                        <div class="signed-name">{{ $signerName }}</div>
                    @endif
                    Firma del cliente
                </div>
            </div>
        </div>
    @endif

    {{-- §16/§17 — pie de página con "Página X de Y" real, repetido en cada página. Único
         mecanismo de dompdf con soporte real de paginación — ver docblock de QuotePdfService. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $companyText = "ILLUMINATIONS USA CORP - Calle 57 Este, Times Square Plaza - Obarrio, Panama - www.illuminations.com.pa";
            $w1 = $fontMetrics->getTextWidth($companyText, $font, 7);
            $pdf->page_text((612 - $w1) / 2, 762, $companyText, $font, 7, array(0.55, 0.55, 0.55));

            $pageText = "Pagina {PAGE_NUM} de {PAGE_COUNT}";
            $w2 = $fontMetrics->getTextWidth("Pagina 00 de 00", $font, 7);
            $pdf->page_text((612 - $w2) / 2, 774, $pageText, $font, 7, array(0.55, 0.55, 0.55));
        }
    </script>
</body>
</html>
