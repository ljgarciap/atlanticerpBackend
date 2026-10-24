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
    .estado-pill.por_confirmar { background: #fef3c7; color: #92400e; }
    .cliente-block { display: table; width: 100%; background: #f7f5ef; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .cliente-block .col { display: table-cell; vertical-align: top; width: 50%; }
    .cliente-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .cliente-nombre { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
    .cliente-linea { color: #6b7280; font-size: 9.5px; line-height: 1.6; }
    .totales { width: 260px; margin-left: auto; margin-top: 10px; }
    .totales .fila { display: table; width: 100%; padding: 3px 0; font-size: 10.5px; }
    .totales .fila .label { display: table-cell; color: #6b7280; }
    .totales .fila .valor { display: table-cell; text-align: right; }
    .totales .fila.total-final .label,
    .totales .fila.total-final .valor { font-weight: bold; font-size: 12.5px; color: #3D7E7A; }
</style>
</head>
<body>
    {{-- Mismo encabezado que payment-receipt-pdf.blade.php (isótipo + "ATLANTIC" una sola
         vez, RUC/contacto de PdfFooterText/AdminContFiscalSettings — ninguno se muestra si está
         vacío, mismo criterio ya aprobado ahí). --}}
    <div class="header">
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
            <div class="recibo-numero">Recibo de abono {{ $detail['numero_recibo'] }}</div>
            <div class="recibo-fecha">Fecha de registro: {{ \Illuminate\Support\Carbon::parse($detail['fecha_registro'])->format('d/m/Y') }}</div>
            <div>
                <span class="estado-pill {{ $detail['estado'] }}">
                    {{ $detail['estado'] === 'por_confirmar' ? 'Por confirmar' : 'Confirmado' }}
                </span>
            </div>
        </div>
    </div>

    <div class="cliente-block">
        <div class="col">
            <div class="cliente-label">Recibido de</div>
            <div class="cliente-nombre">{{ $detail['cliente'] ?? '—' }}</div>
            <div class="cliente-linea">
                Concepto: {{ $detail['concepto'] }}<br>
                Método de pago: {{ $detail['metodo_pago_label'] }}<br>
                @if ($detail['bank_account'] ?? null)
                    Cuenta destino: {{ $detail['bank_account'] }}<br>
                @else
                    Cuenta destino: Otros<br>
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

    <div class="totales">
        <div class="fila total-final"><div class="label">Monto del abono</div><div class="valor">{{ $moneda }} {{ number_format($detail['monto_original'], 2) }}</div></div>
    </div>

    {{-- Pie de página real vía page_text() de dompdf — mismo mecanismo que payment-receipt-pdf.blade.php. --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
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
