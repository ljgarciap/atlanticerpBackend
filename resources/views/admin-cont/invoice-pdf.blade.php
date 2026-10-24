<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-888/SCRUM-893 — encabezado unificado con el modelo `servicios/service-quote-pdf.blade.php`:
         marca real fija (logo + ATLANTIC + línea teal, `pdf.partials.header`/`.doc-header`)
         en vez del header propio de 2 columnas; título "Factura N°" con el mismo tratamiento que
         "Cotización de Servicio N°" del modelo (`.secondary-header-badge`). Los datos fiscales del
         emisor (nombre legal/RUC/dirección) siguen en el cuerpo, ahora debajo de la marca — sigue
         siendo un documento fiscal, ese dato no es "una segunda marca", es información legal. --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .watermark {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        text-align: center; z-index: 10;
    }
    .watermark span {
        display: inline-block; margin-top: 320px; font-size: 90px; font-weight: bold;
        color: #dc2626; opacity: 0.25; transform: rotate(-35deg);
    }
    .secondary-header-row { margin-bottom: 4px; }
    .secondary-header-badge { display: block; color: #3D7E7A; font-weight: bold; font-size: 16px; line-height: 19px; }
    .secondary-header-sub { color: #6b7280; font-size: 9.5px; line-height: 13px; margin-top: 2px; }
    .empresa-datos { color: #6b7280; font-size: 9.5px; line-height: 1.5; margin: 6px 0 12px; }
    .divider { border-bottom: 1px solid #d1d5db; margin: 10px 0 16px; }
    .cliente-block { background: #f7f5ef; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .cliente-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .cliente-nombre { font-size: 12px; font-weight: bold; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
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
    {{-- SCRUM-1156 — banner de «Sin validez fiscal» (RN4): va en el cuerpo, bajo la marca, nunca en una zona que se recorte al imprimir. --}}
    .sin-validez { border: 1.5px solid #b45309; background: #fffbeb; color: #92400e; border-radius: 4px; padding: 6px 10px; margin: 0 0 10px; }
    .sin-validez-titulo { font-weight: bold; font-size: 12px; text-transform: uppercase; }
    .sin-validez-motivo { font-size: 9.5px; margin-top: 2px; }
</style>
</head>
<body>
    @php
        // SCRUM-1156 — un documento fiscal sale SIEMPRE en español: `SetLocaleFromHeader` cambia el idioma por request según
        // `Accept-Language`, y la plantilla de siempre ya era toda en español; sin fijar el idioma saldría mezclada.
        $t = static fn (string $key): string => __('invoice_pdf.'.$key, [], 'es');
        $sinValidez = ! (($variante ?? null)?->isFiscal() ?? false);
    @endphp
    @if ($detail['es_anulada'])
        {{-- SCRUM-1156 (RN4) — sin validez fiscal Y anulada: las dos marcas conviven en la misma pieza girada. --}}
        <div class="watermark"><span>ANULADA @if ($sinValidez)<br><small style="font-size: 30px;">{{ $t('sin_validez_marca') }}</small>@endif</span></div>
    @endif

    @include('pdf.partials.header')

    @if ($sinValidez)
        <div class="sin-validez">
            <div class="sin-validez-titulo">{{ $t('sin_validez') }}</div>
            @if (($variante->motivo ?? null) !== null)
                <div class="sin-validez-motivo">{{ $t('motivo.'.$variante->motivo) }}</div>
            @endif
        </div>
    @endif

    {{-- SCRUM-888 — título alineado consistente con Cotización: mismo tratamiento que
         "Cotización de Servicio N°" (`.secondary-header-badge` del modelo). --}}
    <div class="secondary-header-row">
        <div class="secondary-header-badge">Factura {{ $detail['numero_factura'] ?? '—' }}</div>
        <div class="secondary-header-sub">{{ $detail['fecha_factura'] ?? '—' }}</div>
    </div>

    {{-- SCRUM-857/858 — la factura es un documento fiscal: el nombre legal, RUC y dirección del
         emisor SÍ deben ir en el cuerpo (no es una segunda "marca", la marca ya está arriba, una
         sola vez). El guion del RUC solo si hay dígito verificador. --}}
    <div class="empresa-datos">
        @if (trim($fiscal->nombre_comercial) !== ''){{ $fiscal->nombre_comercial }}<br>@endif
        @if (trim($fiscal->ruc) !== '')RUC {{ $fiscal->ruc }}{{ trim($fiscal->dv) !== '' ? "-{$fiscal->dv}" : '' }}<br>@endif
        @if (trim($fiscal->telefono_contacto) !== '')Tel. {{ $fiscal->telefono_contacto }}<br>@endif
        {{ $fiscal->direccion_fiscal }}
    </div>
    <div class="divider"></div>

    <div class="cliente-block">
        <div class="cliente-label">Cliente</div>
        <div class="cliente-nombre">{{ $detail['receptor_nombre'] ?? $detail['cliente'] }}</div>
        @if (($detail['cliente_receptor_tipo'] ?? null) === '02')
            {{-- SCRUM-1003 (D2) — Consumidor Final: el PDF interno conserva el nombre real del cliente, pero ante la DGI el
                 documento se emite como CONSUMIDOR FINAL y la representación impresa fiscal (CAFE) la emite el PAC: este PDF
                 no debe presentarse como ese documento. --}}
            <div class="empresa-datos">Consumidor final — sin RUC</div>
        @elseif ($detail['cliente_ruc'] ?? null)
            <div class="empresa-datos">RUC {{ $detail['cliente_ruc'] }}</div>
        @endif
        @if ($detail['cotizacion_folio'] ?? null)
            <div class="empresa-datos">Cotización {{ $detail['cotizacion_folio'] }}</div>
        @endif
        @if ($detail['vendedor'] ?? null)
            <div class="empresa-datos">Vendedor: {{ $detail['vendedor'] }}</div>
        @endif
        {{-- SCRUM-929 — qué cubre esta factura (solo facturas con líneas; las legadas no lo llevan). --}}
        @if ($detail['concepto'] ?? null)
            <div class="empresa-datos">{{ $detail['concepto'] }}</div>
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Referencia pública</th>
                <th>Descripción</th>
                <th class="numeric">Cantidad</th>
                <th class="numeric">Precio unitario</th>
                <th class="numeric">Subtotal</th>
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
                <tr><td colspan="5" style="text-align:center; color:#9ca3af;">Sin ítems</td></tr>
            @endforelse
        </tbody>
    </table>

    @include('admin-cont.partials.invoice-totales')

    @include('pdf.partials.footer', ['footerCompanyText' => $footerCompanyText ?? null])
</body>
</html>
