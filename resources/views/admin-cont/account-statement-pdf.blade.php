<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-857 (REQ-456) — mismo criterio de @page que quote-pdf.blade.php: márgenes simétricos
         izq/der (40px) para que la tabla quede centrada sin recortarse, y espacio reservado abajo
         para el pie de página real (page_text(), ver el bloque <script type="text/php"> al final). --}}
    @page { margin: 90px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }

    .doc-header { position: fixed; top: -80px; left: 0; right: 0; height: 70px; }
    .doc-header .brand-row { display: table; width: 100%; }
    .doc-header .brand-cell { display: table-cell; vertical-align: middle; }
    .doc-header .logo-cell { display: table-cell; width: 32px; vertical-align: middle; }
    .doc-header .logo-cell img { height: 26px; }
    .doc-header .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; padding-left: 10px; }
    .doc-header .brand-line { border-bottom: 2px solid #5BA5A0; margin-top: 10px; }

    h1 { font-size: 14px; color: #3D7E7A; margin: 0 0 2px; }
    .subtitle { font-size: 9.5px; color: #6b7280; margin-bottom: 14px; }
    .divider { border-bottom: 1px solid #d1d5db; margin: 10px 0 14px; }

    {{-- REQ-456: datos previos a la tabla en columnas, alineados a la derecha — sin ningún
         recuadro/borde envolviendo la información (a diferencia de la pantalla en vivo,
         `EstadoCuentaPage.tsx`, que sí usa una tarjeta con borde — eso queda igual, es solo el
         documento descargable el que no debe tener ningún recuadro). Mismas clases .info-row/
         .info-col que quote-pdf.blade.php, mismo criterio de columnas. --}}
    .info-row { display: table; width: 100%; margin-bottom: 14px; }
    .info-col { display: table-cell; width: 50%; vertical-align: top; font-size: 11px; line-height: 16px; }
    .info-col.right { text-align: right; }
    .info-label { font-weight: bold; }
    .cliente-nombre { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
    .meta-line { color: #6b7280; font-size: 9.5px; }
    .saldo-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .saldo-valor { font-size: 15px; font-weight: bold; color: #3D7E7A; margin-bottom: 8px; }
    .saldo-valor.a-favor { color: #92600f; }
    .pago-linea { font-size: 10px; color: #6b7280; line-height: 15px; }

    .nota-contexto { font-size: 10px; color: #6b7280; margin-bottom: 12px; }

    table.items { width: 100%; border-collapse: collapse; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 5px; font-size: 8px; line-height: 10px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td { padding: 4px 5px; border-bottom: 1px solid #f0f0f0; font-size: 9.5px; line-height: 12px; vertical-align: top; }
    tr.subtotal-row td { background: #f3f4f6; font-weight: bold; }
</style>
</head>
<body>
    <div class="doc-header">
        <div class="brand-row">
            <div class="logo-cell"><img src="{{ $logoDataUri }}"></div>
            <div class="brand-cell brand-name">ATLANTIC</div>
        </div>
        <div class="brand-line"></div>
    </div>

    <h1>Estado de cuenta — Cuentas por cobrar</h1>
    <div class="subtitle">Administración &amp; Contabilidad</div>
    <div class="divider"></div>

    <div class="info-row">
        <div class="info-col">
            <div class="cliente-nombre">{{ $detail['cliente'] }}{{ $detail['proyecto'] ? ' · '.$detail['proyecto'] : '' }}</div>
            <div class="meta-line">
                {{ $tarifaLabel ?? 'Sin tarifa definida' }} · {{ $regimenLabel }} · Términos {{ $detail['terminos_pago'] }}
            </div>
            @if (($detail['proyectos_count'] ?? null) !== null && $detail['proyectos_count'] > 1)
                <div class="nota-contexto">Este cliente tiene {{ $detail['proyectos_count'] }} proyectos.</div>
            @endif
            @if ($detail['nota_contexto'] ?? null)
                <div class="nota-contexto">{{ $detail['nota_contexto'] }}</div>
            @endif
        </div>
        <div class="info-col right">
            <div class="saldo-label">{{ $detail['saldo_a_favor'] ? 'Saldo a favor' : 'Saldo actual' }}</div>
            <div class="saldo-valor {{ $detail['saldo_a_favor'] ? 'a-favor' : '' }}">
                {{ $moneda }} {{ number_format(abs($detail['saldo']), 2) }}
            </div>
            <div class="pago-linea">Pago a: {{ $detail['pago_a'] ?? '—' }}</div>
            <div class="pago-linea">Cuenta número: {{ $detail['numero_cuenta'] ?? '—' }}</div>
            <div class="pago-linea">Tipo de cuenta: {{ $tipoCuentaLabel ?? '—' }}</div>
            <div class="pago-linea">Banco: {{ $detail['banco'] ?? '—' }}</div>
            <div class="pago-linea">Responsable: {{ $detail['responsable'] ?? '—' }}</div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Proyecto</th>
                <th>N° Factura</th>
                <th>Concepto</th>
                <th>N° Cotización</th>
                <th>Guía de entrega</th>
                <th class="numeric">Débito</th>
                <th class="numeric">Crédito</th>
                <th class="numeric">Saldo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($detail['movimientos'] as $m)
                <tr>
                    <td>{{ $m['fecha'] }}</td>
                    <td>{{ $m['proyecto'] ?? '—' }}</td>
                    <td>{{ $m['numero_factura'] ?? '—' }}</td>
                    <td>{{ [
                        'factura'          => 'Factura',
                        'cobro'            => 'Cobro',
                        'descuento'        => 'Descuento',
                        'devolucion'       => 'Devolución',
                        'anulacion'        => 'Anulación',
                        'compensacion'     => 'Compensación',
                        'devolucion_saldo' => 'Devolución de saldo',
                    ][$m['tipo']] ?? $m['tipo'] }}</td>
                    <td>{{ $m['cotizacion_folio'] ?? '—' }}</td>
                    <td>{{ $m['guia_entrega_numero'] ?? '—' }}</td>
                    <td class="numeric">{{ $m['debito'] > 0 ? $moneda.' '.number_format($m['debito'], 2) : '—' }}</td>
                    <td class="numeric">{{ $m['credito'] > 0 ? $moneda.' '.number_format($m['credito'], 2) : '—' }}</td>
                    <td class="numeric">{{ $moneda }} {{ number_format($m['saldo'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center; color:#9ca3af;">Sin movimientos</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- SCRUM-857 (REQ-456) — pie de página REAL y fijo (marca + dirección + numeración), sin
         "AtlanticERP" ni URLs del sistema — mismo mecanismo que quote-pdf.blade.php (único con soporte
         real de paginación en dompdf, ver docblock de AccountStatementPdfService::generate()). --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            {{-- Pre-QA (SCRUM-857/858, 2026-09-09) — {{ }} normal aplica htmlspecialchars() ADEMAS
                 del addslashes() que ya hace PdfFooterText::forFiscal() para este mismo contexto
                 (string PHP dentro de <script type="text/php">) -- una comilla en el nombre/
                 direccion terminaba como "\&#039;" literal en el PDF en vez de "'". {!! !!} es lo
                 correcto acá: el valor ya viene pre-escapado para EXACTAMENTE este contexto, no
                 para HTML. --}}
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
