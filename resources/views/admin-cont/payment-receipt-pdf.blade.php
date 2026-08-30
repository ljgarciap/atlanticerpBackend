<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { display: table; width: 100%; margin-bottom: 18px; }
    .header .col { display: table-cell; vertical-align: top; width: 50%; }
    .header .col.right { text-align: right; }
    .logo { height: 40px; margin-bottom: 6px; }
    .empresa-nombre { font-size: 14px; font-weight: bold; }
    .empresa-datos { color: #6b7280; font-size: 9.5px; line-height: 1.5; }
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
    .confirmacion-block { margin-top: 14px; color: #6b7280; font-size: 9.5px; }
    .footer { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 8px; color: #9ca3af; font-size: 9px; text-align: center; }
</style>
</head>
<body>
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
                @if (($detail['saldo_favor_aplicado'] ?? 0) > 0)
                    Saldo a favor aplicado: -{{ $moneda }} {{ number_format($detail['saldo_favor_aplicado'], 2) }}
                    (total de facturas: {{ $moneda }} {{ number_format($detail['total_facturas'], 2) }})<br>
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

    <div class="totales">
        <div class="fila total-final"><div class="label">Total recibido</div><div class="valor">{{ $moneda }} {{ number_format($detail['monto_recibido'], 2) }}</div></div>
    </div>

    @if ($detail['confirmacion'] ?? null)
        <div class="confirmacion-block">
            Confirmación {{ $detail['confirmacion']['numero_confirmacion'] }} — {{ $detail['confirmacion']['confirmado_por'] ?? '—' }}
            el {{ \Illuminate\Support\Carbon::parse($detail['confirmacion']['confirmado_at'])->format('d/m/Y') }}
        </div>
    @endif

    <div class="footer">{{ $fiscal->nombre_comercial }} · Documento generado por AtlanticERP</div>
</body>
</html>
