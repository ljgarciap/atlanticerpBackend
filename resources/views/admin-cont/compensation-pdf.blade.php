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
    .brand-name { font-size: 15px; font-weight: bold; margin-bottom: 2px; }
    .empresa-datos { color: #6b7280; font-size: 9px; line-height: 1.5; }
    .doc-numero { font-size: 16px; font-weight: bold; color: #3D7E7A; }
    .doc-fecha { color: #6b7280; font-size: 9.5px; margin-top: 2px; }
    .cliente-block { background: #f7f5ef; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .cliente-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .cliente-nombre { font-size: 12px; font-weight: bold; margin-bottom: 2px; }
    .cliente-linea { color: #6b7280; font-size: 9.5px; line-height: 1.6; }
    table.lineas { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lineas th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 5px 6px; }
    table.lineas td { padding: 6px; border-bottom: 1px solid #eef0f2; font-size: 10.5px; }
    table.lineas th.num, table.lineas td.num { text-align: right; }
    .totales { width: 260px; margin-left: auto; margin-top: 12px; }
    .totales .fila { display: table; width: 100%; padding: 3px 0; font-size: 12.5px; font-weight: bold; color: #3D7E7A; }
    .totales .fila .label { display: table-cell; }
    .totales .fila .valor { display: table-cell; text-align: right; }
    .reversado { color: #b91c1c; font-weight: bold; font-size: 10px; margin-top: 6px; }
</style>
</head>
<body>
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
            <div class="doc-numero">Compensación {{ $detail['folio'] }}</div>
            <div class="doc-fecha">Fecha: {{ \Illuminate\Support\Carbon::parse($detail['confirmed_at'])->format('d/m/Y') }}</div>
            @if ($detail['estado'] === 'reversado')
                <div class="reversado">REVERSADA</div>
            @endif
        </div>
    </div>

    <div class="cliente-block">
        <div class="cliente-label">Cliente</div>
        <div class="cliente-nombre">{{ $detail['cliente'] ?: '—' }}</div>
        <div class="cliente-linea">Registrado por: {{ $detail['registrado_por'] ?? '—' }}</div>
    </div>

    <table class="lineas">
        <thead><tr><th>Dinero aplicado desde</th><th>Factura</th><th class="num">Monto</th></tr></thead>
        <tbody>
            @foreach ($detail['lineas'] as $l)
                <tr><td>{{ $l['fuente'] }}</td><td>{{ $l['factura'] }}</td><td class="num">{{ $moneda }} {{ number_format($l['monto'], 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="totales">
        <div class="fila"><div class="label">Total compensado</div><div class="valor">{{ $moneda }} {{ number_format($detail['monto_total'], 2) }}</div></div>
    </div>

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
