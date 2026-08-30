<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .watermark {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        text-align: center; z-index: 10;
    }
    .watermark span {
        display: inline-block; margin-top: 320px; font-size: 90px; font-weight: bold;
        color: #dc2626; opacity: 0.25; transform: rotate(-35deg);
    }
    .header { display: table; width: 100%; margin-bottom: 18px; }
    .header .col { display: table-cell; vertical-align: top; width: 50%; }
    .header .col.right { text-align: right; }
    .logo { height: 40px; margin-bottom: 6px; }
    .empresa-nombre { font-size: 14px; font-weight: bold; }
    .empresa-datos { color: #6b7280; font-size: 9.5px; line-height: 1.5; }
    .factura-numero { font-size: 16px; font-weight: bold; color: #3D7E7A; }
    .factura-fecha { color: #6b7280; font-size: 9.5px; }
    .cliente-block { background: #f7f5ef; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .cliente-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .cliente-nombre { font-size: 12px; font-weight: bold; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    table.items th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    table.items th.numeric, table.items td.numeric { text-align: right; }
    table.items td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .totales { width: 260px; margin-left: auto; }
    .totales .fila { display: table; width: 100%; padding: 3px 0; font-size: 10.5px; }
    .totales .fila .label { display: table-cell; color: #6b7280; }
    .totales .fila .valor { display: table-cell; text-align: right; }
    .totales .fila.total-factura.tachado .label,
    .totales .fila.total-factura.tachado .valor { text-decoration: line-through; color: #9ca3af; }
    .totales .fila.total-final .label,
    .totales .fila.total-final .valor { font-weight: bold; font-size: 12.5px; color: #3D7E7A; }
    .pago-block { margin-top: 22px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    .pago-label { color: #6b7280; font-size: 9px; text-transform: uppercase; margin-bottom: 4px; }
</style>
</head>
<body>
    @if ($detail['es_anulada'])
        <div class="watermark"><span>ANULADA</span></div>
    @endif

    <div class="header">
        <div class="col">
            <img class="logo" src="{{ $logoDataUri }}">
            <div class="empresa-nombre">{{ $fiscal->nombre_comercial }}</div>
            <div class="empresa-datos">
                RUC {{ $fiscal->ruc }}-{{ $fiscal->dv }}<br>
                {{ $fiscal->direccion_fiscal }}
            </div>
        </div>
        <div class="col right">
            <div class="factura-numero">Factura {{ $detail['numero_factura'] ?? '—' }}</div>
            <div class="factura-fecha">{{ $detail['fecha_factura'] ?? '—' }}</div>
        </div>
    </div>

    <div class="cliente-block">
        <div class="cliente-label">Cliente</div>
        <div class="cliente-nombre">{{ $detail['cliente'] }}</div>
        @if ($detail['cotizacion_folio'] ?? null)
            <div class="empresa-datos">Cotización {{ $detail['cotizacion_folio'] }}</div>
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="numeric">Cantidad</th>
                <th class="numeric">Precio unitario</th>
                <th class="numeric">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse (($detail['items'] ?? []) as $item)
                <tr>
                    <td>{{ $item['descripcion'] }}</td>
                    <td class="numeric">{{ $item['cantidad'] }}</td>
                    <td class="numeric">{{ $moneda }} {{ number_format($item['precio_unitario'], 2) }}</td>
                    <td class="numeric">{{ $moneda }} {{ number_format($item['subtotal'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#9ca3af;">Sin ítems</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totales">
        <div class="fila"><div class="label">Subtotal</div><div class="valor">{{ $moneda }} {{ number_format($detail['subtotal'] ?? 0, 2) }}</div></div>
        @if (($detail['descuentos'] ?? 0) > 0)
            <div class="fila"><div class="label">Descuentos</div><div class="valor">-{{ $moneda }} {{ number_format($detail['descuentos'], 2) }}</div></div>
        @endif
        <div class="fila"><div class="label">ITBMS</div><div class="valor">{{ $moneda }} {{ number_format($detail['itbms'] ?? 0, 2) }}</div></div>

        @if (($detail['saldo_aplicado'] ?? 0) > 0)
            <div class="fila total-factura tachado"><div class="label">Total factura</div><div class="valor">{{ $moneda }} {{ number_format($detail['total'] ?? $detail['monto'], 2) }}</div></div>
            <div class="fila"><div class="label">Saldo a favor aplicado</div><div class="valor">-{{ $moneda }} {{ number_format($detail['saldo_aplicado'], 2) }}</div></div>
            <div class="fila total-final"><div class="label">Total a pagar</div><div class="valor">{{ $moneda }} {{ number_format($detail['total_a_pagar'], 2) }}</div></div>
        @else
            <div class="fila total-final"><div class="label">Total factura</div><div class="valor">{{ $moneda }} {{ number_format($detail['total'] ?? $detail['monto'], 2) }}</div></div>
        @endif
    </div>

    <div class="pago-block">
        <div class="pago-label">Datos de pago</div>
        <div class="empresa-datos">
            Pago a: {{ $fiscal->nombre_comercial }}<br>
            Cuenta número: {{ $detail['cuenta_pago'] ?? '—' }}<br>
            Responsable: {{ $detail['responsable'] ?? '—' }}
        </div>
    </div>
</body>
</html>
