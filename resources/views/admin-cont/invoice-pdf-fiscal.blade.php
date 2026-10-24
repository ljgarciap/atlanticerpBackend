<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-1156 — PDF de una factura CERTIFICADA ante la DGI (`InvoicePdfVariant::FISCAL`): título propio, clave de acceso, QR del PAC,
         ambiente y leyenda de representación gráfica. Es una copia interna: el comprobante oficial lo emite el PAC (spec §12). Solo tablas y
         bloques (dompdf no soporta flex/grid ni `<svg>` inline). Montos y datos de pago vienen del parcial compartido con `invoice-pdf`. --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .watermark { position: fixed; top: 0; left: 0; width: 100%; height: 100%; text-align: center; z-index: 10; }
    .watermark span { display: inline-block; margin-top: 320px; font-size: 90px; font-weight: bold; color: #dc2626; opacity: 0.25; transform: rotate(-35deg); }
    .watermark small { font-size: 28px; }
    table.cabecera, table.doc-datos, table.items { width: 100%; border-collapse: collapse; }
    table.cabecera td { vertical-align: top; }
    .titulo { font-size: 17px; font-weight: bold; color: #3D7E7A; line-height: 20px; }
    .subtitulo { color: #6b7280; font-size: 9.5px; margin: 2px 0 8px; }
    .empresa-datos { color: #4b5563; font-size: 9.5px; line-height: 1.5; margin: 0 0 8px; }
    .empresa-nombre { color: #2a2520; font-size: 12px; font-weight: bold; }
    .clave-label { font-size: 9.5px; font-weight: bold; }
    .clave-url { font-size: 8.5px; color: #3D7E7A; }
    .clave { font-family: DejaVu Sans Mono, monospace; font-size: 7.5px; margin: 2px 0 8px; }
    .receptor-tipo { font-size: 9.5px; }
    .qr-cell { width: 215px; text-align: right; }
    table.doc-datos { margin: 10px 0 10px; }
    table.doc-datos th { background: #E3F0EE; color: #3D7E7A; text-align: center; padding: 4px 6px; font-size: 9px; text-transform: uppercase; border: 1px solid #c7ddd9; }
    table.doc-datos td { text-align: center; padding: 5px 6px; font-size: 10.5px; border: 1px solid #c7ddd9; }
    .cliente-block { border: 1px solid #c7ddd9; border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; }
    .cliente-label { color: #3D7E7A; font-size: 9px; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; }
    .cliente-nombre { font-size: 12px; font-weight: bold; }
    .cliente-nota { color: #6b7280; font-size: 9px; margin-top: 3px; }
    table.items { margin-bottom: 16px; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td.numeric { white-space: nowrap; }
    table.items td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .totales { width: 260px; margin-left: auto; }
    .totales .fila { display: table; width: 100%; padding: 3px 0; font-size: 10.5px; }
    .totales .fila .label { display: table-cell; color: #6b7280; }
    .totales .fila .valor { display: table-cell; text-align: right; }
    .totales .fila.total-factura.tachado .label,
    .totales .fila.total-factura.tachado .valor { text-decoration: line-through; color: #9ca3af; }
    .totales .fila.total-final .label,
    .totales .fila.total-final .valor { font-weight: bold; font-size: 12.5px; color: #3D7E7A; }
    .pago-block { margin-top: 22px; border-top: 1px solid #e5e7eb; padding-top: 10px; page-break-inside: avoid; }
    .totales { page-break-inside: avoid; }
    .pago-label { color: #6b7280; font-size: 9px; text-transform: uppercase; margin-bottom: 4px; }
    .aviso { border: 1.5px solid #b45309; background: #fffbeb; color: #92400e; border-radius: 4px; padding: 6px 10px; margin: 0 0 10px; font-size: 10px; font-weight: bold; }
    .aviso-pruebas { border-color: #b91c1c; background: #fef2f2; color: #991b1b; text-transform: uppercase; font-size: 11px; }
    .pie-leyenda { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 8.5px; line-height: 1.4; }
</style>
</head>
<body>
    @php
        // Siempre en español, igual que el PDF de siempre (ver nota en `invoice-pdf.blade.php`).
        $t = static fn (string $key): string => __('invoice_pdf.'.$key, [], 'es');
        $receptorEsCF = ($detail['cliente_receptor_tipo'] ?? null) === '02';
        $marcaAnulacion = match ($anulacion ?? null) {
            'anulada'    => $t('anulada'),
            'en_tramite' => $t('anulacion_tramite'),
            default      => null,
        };
        $marcaPruebas = ($esPruebas ?? false) ? $t('ambiente_pruebas') : null;
        // «ANULACIÓN EN TRÁMITE» es más larga que «ANULADA»: a 90px se parte en dos líneas gigantes sobre el contenido.
        $marcaPx = ($anulacion ?? null) === 'en_tramite' ? 56 : 90;
        $fechaEmision = ($doc['emision'] ?? null)?->format('d/m/Y H:i') ?? ($detail['fecha_factura'] ?? '—');
    @endphp

    @if ($marcaAnulacion !== null || $marcaPruebas !== null)
        <div class="watermark"><span style="font-size: {{ $marcaPx }}px;">@if ($marcaAnulacion !== null){{ $marcaAnulacion }}@endif @if ($marcaPruebas !== null)<br><small>{{ $marcaPruebas }}</small>@endif</span></div>
    @endif

    @include('pdf.partials.header')

    @if ($marcaPruebas !== null)
        <div class="aviso aviso-pruebas">{{ $marcaPruebas }}</div>
    @endif
    @if (($anulacion ?? null) === 'en_tramite')
        <div class="aviso">{{ $t('anulacion_tramite_nota') }}</div>
    @endif

    <table class="cabecera">
        <tr>
            <td>
                <div class="titulo">{{ $t('titulo') }}</div>
                <div class="subtitulo">{{ $doc['serie'] ?? '' }}{{ ($doc['serie'] ?? '') !== '' ? ' - ' : '' }}{{ $doc['numero'] ?? ($detail['numero_factura'] ?? '—') }}</div>
                <div class="empresa-datos">
                    @if (trim($fiscal->nombre_comercial) !== '')<span class="empresa-nombre">{{ $fiscal->nombre_comercial }}</span><br>@endif
                    @if (trim($fiscal->ruc) !== '')RUC {{ $fiscal->ruc }}{{ trim($fiscal->dv) !== '' ? " DV {$fiscal->dv}" : '' }}<br>@endif
                    @if (trim($fiscal->telefono_contacto) !== '')Tel. {{ $fiscal->telefono_contacto }}<br>@endif
                    {{ $fiscal->direccion_fiscal }}
                </div>
                <div class="clave-label">{{ $t('clave_acceso') }}</div>
                <div class="clave-url">{{ $variante->consultaUrl }}</div>
                <div class="clave">{{ $variante->clave }}</div>
                <div class="receptor-tipo"><b>{{ $t('tipo_receptor') }}:</b> {{ $receptorEsCF ? $t('consumidor_final') : $t('contribuyente') }}</div>
            </td>
            <td class="qr-cell">
                {{-- SCRUM-1156 — 200 px (~5.3 cm): con un `qr_content` real de ~480 caracteres (~105 módulos) a 120 px (~3.2 cm) cada módulo medía ~0.28 mm y no se escaneaba. --}}
                <img src="{{ $qrDataUri }}" width="{{ \App\Shared\Support\QrPng::DISPLAY_PX }}" height="{{ \App\Shared\Support\QrPng::DISPLAY_PX }}" alt="QR">
            </td>
        </tr>
    </table>

    <table class="doc-datos">
        <thead>
            <tr>
                <th>{{ $t('fecha_emision') }}</th>
                <th>{{ $t('serie') }}</th>
                <th>{{ $t('numero') }}</th>
                <th>{{ $t('vendedor') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $fechaEmision }}</td>
                <td>{{ $doc['serie'] ?? '—' }}</td>
                <td>{{ $doc['numero'] ?? ($detail['numero_factura'] ?? '—') }}</td>
                <td>{{ ($detail['vendedor'] ?? null) ?: '—' }}</td>
            </tr>
        </tbody>
    </table>

    {{-- SCRUM-1156 (spec §12.2) — «Datos del cliente» imprime lo que viajó al PAC (receptor congelado de la factura), no el cliente en vivo:
         un Consumidor Final es «CONSUMIDOR FINAL» sin RUC ni DV; el nombre real va aparte, rotulado como referencia interna. --}}
    <div class="cliente-block">
        <div class="cliente-label">{{ $t('datos_cliente') }}</div>
        @if ($receptorEsCF)
            <div class="cliente-nombre">{{ $t('consumidor_final') }}</div>
            @if (($detail['cliente'] ?? null) !== null)
                <div class="cliente-nota">{{ $t('cliente_referencia') }}: {{ $detail['cliente'] }}</div>
            @endif
            <div class="cliente-nota">{{ $t('uso_posterior') }}</div>
        @else
            <div class="cliente-nombre">{{ ($detail['receptor_nombre'] ?? null) ?: ($detail['cliente'] ?? '—') }}</div>
            @if (($detail['cliente_ruc'] ?? null) !== null && trim((string) $detail['cliente_ruc']) !== '')
                <div class="cliente-nota">{{ $t('ruc') }} {{ $detail['cliente_ruc'] }}@if (($doc['receptor_dv'] ?? null) !== null && trim((string) $doc['receptor_dv']) !== '') &nbsp; {{ $t('dv') }} {{ $doc['receptor_dv'] }}@endif</div>
            @endif
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>{{ $t('codigo') }}</th>
                <th>{{ $t('descripcion') }}</th>
                <th class="numeric">{{ $t('unidades') }}</th>
                <th class="numeric">{{ $t('precio') }}</th>
                <th class="numeric">{{ $t('importe') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse (($detail['items'] ?? []) as $item)
                <tr>
                    <td>{{ $item['referencia_publica'] ?? '—' }}</td>
                    <td>{{ $item['descripcion'] }}</td>
                    <td class="numeric">{{ $item['cantidad'] }}</td>
                    <td class="numeric">{{ $moneda }} {{ number_format($item['precio_unitario'], 2) }}</td>
                    <td class="numeric">{{ $moneda }} {{ number_format($item['subtotal'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center; color:#9ca3af;">—</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('admin-cont.partials.invoice-totales', ['pagoTitulo' => $t('pago_titulo')])

    <div class="pie-leyenda">{{ $t('pie') }}</div>

    @include('pdf.partials.footer', ['footerCompanyText' => $footerCompanyText ?? null])
</body>
</html>
