<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { display: table; width: 100%; margin-bottom: 18px; border-bottom: 2px solid #5BA5A0; padding-bottom: 10px; }
    .header .col { display: table-cell; vertical-align: top; width: 50%; }
    .header .col.right { text-align: right; }
    .logo { height: 36px; margin-bottom: 6px; }
    .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; margin-bottom: 2px; }
    .empresa-datos { color: #6b7280; font-size: 9px; line-height: 1.5; }
    .recibo-numero { font-size: 16px; font-weight: bold; color: #3D7E7A; }
    .recibo-fecha { color: #6b7280; font-size: 9.5px; margin-top: 2px; }
    .estado-pill { display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 999px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
    .estado-pill.confirmado { background: #E3F0EE; color: #3D7E7A; }
    .estado-pill.esperando_confirmacion { background: #fef3c7; color: #92400e; }
    .cliente-block { display: table; width: 100%; background: #f7f5ef; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .cliente-block .col { display: table-cell; vertical-align: top; width: 50%; }
    .cliente-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .cliente-nombre { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
    .cliente-linea { color: #6b7280; font-size: 9.5px; line-height: 1.6; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .totales { width: 260px; margin-left: auto; }
    .totales .fila { display: table; width: 100%; padding: 3px 0; font-size: 10.5px; }
    .totales .fila .label { display: table-cell; color: #6b7280; }
    .totales .fila .valor { display: table-cell; text-align: right; }
    .totales .fila.tachado .label,
    .totales .fila.tachado .valor { text-decoration: line-through; color: #9ca3af; }
    .totales .fila.total-final .label,
    .totales .fila.total-final .valor { font-weight: bold; font-size: 12.5px; color: #3D7E7A; }
</style>
</head>
<body>
    <div class="header">
        {{-- Rebote de Daniela (SCRUM-858, 2026-09-10) — el encabezado repetía "ATLANTIC"
             (isótipo + marca + nombre_comercial + RUC/dirección). Se unifica con el estándar ya
             aprobado de cotizaciones y Estado de Cuenta: isótipo + "ATLANTIC" una sola vez,
             nombre legal completo + dirección en el pie de página (PdfFooterText).
             Corrección SCRUM-550 (rebote de Daniela 2026-09-10): PdfFooterText::forFiscal() NUNCA
             incluyó el RUC (solo nombre/dirección/sitio web, ver su docblock) — la premisa de la
             nota de arriba ("RUC vive en el pie de página") era falsa, el dato simplemente
             desapareció del documento. Se restaura acá, en el header, junto con el contacto de la
             empresa (RN1) — ninguno de los 2 se muestra si está vacío, mismo criterio que ya
             fijó el fix de "RUC -" suelto (SCRUM-858). --}}
        <div class="col">
            <img class="logo" src="{{ $logoDataUri }}">
            <div class="brand-name">ATLANTIC</div>
            @if (trim($fiscal->ruc) !== '' || trim($fiscal->telefono_contacto) !== '' || trim($fiscal->correo_contacto) !== '')
                <div class="empresa-datos">
                    @if (trim($fiscal->ruc) !== '')
                        RUC {{ $fiscal->ruc }}{{ trim($fiscal->dv) !== '' ? "-{$fiscal->dv}" : '' }}<br>
                    @endif
                    @if (trim($fiscal->telefono_contacto) !== '' || trim($fiscal->correo_contacto) !== '')
                        {{ collect([$fiscal->telefono_contacto, $fiscal->correo_contacto])->filter(fn ($v) => trim((string) $v) !== '')->implode(' · ') }}
                    @endif
                </div>
            @endif
        </div>
        <div class="col right">
            <div class="recibo-numero">Recibo de cobro {{ $detail['numero_recibo'] }}</div>
            <div class="recibo-fecha">Fecha de registro: {{ \Illuminate\Support\Carbon::parse($detail['created_at'])->format('d/m/Y') }}</div>
            <div>
                <span class="estado-pill {{ $detail['estado'] }}">
                    {{ $detail['estado'] === 'esperando_confirmacion' ? 'Esperando confirmación' : 'Confirmado' }}
                </span>
            </div>
        </div>
    </div>

    <div class="cliente-block">
        <div class="col">
            <div class="cliente-label">Recibido de</div>
            <div class="cliente-nombre">{{ $detail['cliente'] ?? '—' }}</div>
            <div class="cliente-linea">
                Método de pago: {{ $detail['metodo_pago_label'] }}<br>
                @if ($detail['bank_account'] ?? null)
                    Cuenta destino: {{ $detail['bank_account'] }}<br>
                @endif
                Referencia: {{ $detail['referencia'] ?? '—' }}
            </div>
        </div>
        <div class="col">
            <div class="cliente-label">Registrado por</div>
            <div class="cliente-linea">
                {{ $detail['registrado_por'] ?? '—' }}<br>
                Recibo N°: {{ $detail['numero_recibo'] }}
            </div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Factura</th>
                <th class="numeric">Monto aplicado</th>
            </tr>
        </thead>
        <tbody>
            @forelse (($detail['facturas'] ?? []) as $factura)
                <tr>
                    <td>{{ $factura['numero'] }}</td>
                    <td class="numeric">{{ $moneda }} {{ number_format($factura['monto_aplicado'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" style="text-align:center; color:#9ca3af;">Sin facturas aplicadas</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Corrección SCRUM-550 (rebote de Daniela 2026-09-10) — RN1: "saldo a favor aplicado (si
         aplica), mostrando también el total de facturas antes de aplicarlo" y "debe quedar claro
         cómo esto se relaciona con el Total recibido". La clase .tachado ya existía en el CSS de
         este archivo desde el batch original (REQ-473) pero nunca se usaba en el markup — este
         desglose es exactamente para lo que se definió. Sin saldo a favor aplicado (caso normal),
         la sección se omite del todo y solo queda la fila de Total recibido, sin cambios. --}}
    <div class="totales">
        @if (($detail['saldo_favor_aplicado'] ?? 0) > 0)
            <div class="fila tachado"><div class="label">Total facturas</div><div class="valor">{{ $moneda }} {{ number_format($detail['total_facturas'], 2) }}</div></div>
            <div class="fila"><div class="label">Saldo a favor aplicado</div><div class="valor">-{{ $moneda }} {{ number_format($detail['saldo_favor_aplicado'], 2) }}</div></div>
        @endif
        <div class="fila total-final"><div class="label">Total recibido</div><div class="valor">{{ $moneda }} {{ number_format($detail['monto_recibido'], 2) }}</div></div>
    </div>

    {{-- Corrección SCRUM-550 — "Confirmación CONF-XXX — [nombre] el [fecha]" no está contemplada
         en el requerimiento original (RN1/RN2), se elimina del recibo (sigue disponible en
         $detail['confirmacion'] para la pantalla de detalle del cobro, que no es este documento). --}}

    {{-- SCRUM-858 — pie de página REAL vía page_text() de dompdf, reemplaza el <div class="footer">
         viejo (contenido normal del body, se desplazaba con el contenido en documentos de más de
         una página). Mismo mecanismo/posiciones que quote-pdf.blade.php/guia-entrega-pdf.blade.php.
         Rebote de QA 2026-09-08 — el texto seguía hardcodeado con "Documento generado por AtlanticERP"
         (nunca se había corregido pese a que el `isPhpEnabled` de la corrida anterior sí lo hacía
         RENDERIZAR) — ahora lee los datos reales de la empresa vía $footerCompanyText (PdfFooterText,
         compartido con account-statement-pdf.blade.php), nunca "AtlanticERP". --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            {{-- Pre-QA (SCRUM-858, 2026-09-09) — {{ }} normal aplica htmlspecialchars() ADEMAS del
                 addslashes() que ya hace PdfFooterText::forFiscal() para este mismo contexto
                 (string PHP dentro de <script type="text/php">) -- una comilla en el nombre/
                 direccion terminaba como "\&#039;" literal en el PDF en vez de "'". {!! !!} es lo
                 correcto acá: el valor ya viene pre-escapado para EXACTAMENTE este contexto, no
                 para HTML. Mismo bug/fix en account-statement-pdf.blade.php (SCRUM-857). --}}
            $companyText = "{!! $footerCompanyText !!}";
            $w1 = $fontMetrics->getTextWidth($companyText, $font, 7);
            $pdf->page_text((612 - $w1) / 2, 762, $companyText, $font, 7, array(0.55, 0.55, 0.55));

            $pageText = "Pagina {PAGE_NUM} de {PAGE_COUNT}";
            $w2 = $fontMetrics->getTextWidth("Pagina 00 de 00", $font, 7);
            $pdf->page_text((612 - $w2) / 2, 774, $pageText, $font, 7, array(0.55, 0.55, 0.55));
        }
    </script>
</body>
</html>
