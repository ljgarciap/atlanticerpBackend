<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { display: table; width: 100%; margin-bottom: 18px; border-bottom: 2px solid #5BA5A0; padding-bottom: 10px; }
    .header .col { display: table-cell; vertical-align: top; width: 50%; }
    .header .col.right { text-align: right; }
    .logo { height: 36px; vertical-align: middle; }
    .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; padding-left: 8px; vertical-align: middle; }
    .empresa-datos { color: #6b7280; font-size: 9.5px; line-height: 1.5; margin-top: 6px; }
    .nota-numero { font-size: 16px; font-weight: bold; color: #3D7E7A; }
    .nota-fecha { color: #6b7280; font-size: 9.5px; }
    .estado-badge {
        display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 10px;
        font-size: 9px; font-weight: bold; text-transform: uppercase;
    }
    .estado-badge.aplicada { background: #E3F0EE; color: #3D7E7A; }
    .estado-badge.pendiente_aprobacion { background: #fef3c7; color: #92400e; }
    .estado-badge.rechazada { background: #fee2e2; color: #991b1b; }
    .cliente-block { background: #f7f5ef; border-radius: 4px; padding: 10px 12px; margin-bottom: 16px; }
    .cliente-label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
    .cliente-nombre { font-size: 12px; font-weight: bold; }
    .campos { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .campos td { padding: 5px 0; font-size: 10.5px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    .campos td.label { color: #6b7280; width: 180px; }
    .campos td.valor.factura-link { color: #3D7E7A; font-weight: bold; }
    .totales { width: 260px; margin-left: auto; margin-top: 10px; }
    .totales .fila { display: table; width: 100%; padding: 3px 0; font-size: 10.5px; }
    .totales .fila .label { display: table-cell; color: #6b7280; }
    .totales .fila .valor { display: table-cell; text-align: right; }
    .totales .fila.total-final .label,
    .totales .fila.total-final .valor { font-weight: bold; font-size: 12.5px; color: #3D7E7A; }
    .motivo-block { margin-top: 18px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    .motivo-label { color: #6b7280; font-size: 9px; text-transform: uppercase; margin-bottom: 4px; }
</style>
</head>
<body>
    @php
        $tipoLabel = match ($detail['tipo']) {
            'descuento_comercial'  => 'Descuento comercial',
            'devolucion_mercancia' => 'Devolución de mercancía',
            'anulacion_completa'   => $detail['subtipo_anulacion'] === 'correccion' ? 'Anulación completa — Corrección de datos' : 'Anulación completa',
            default                => $detail['tipo'],
        };
        $resultadoLabel = match ($detail['resultado']) {
            'aplicado_saldo' => 'Aplicado al saldo pendiente de la factura',
            'devuelto'       => 'Devuelto al cliente',
            'saldo_favor'    => 'Saldo a favor del cliente',
            default          => '—',
        };
        $estadoLabel = match ($detail['estado']) {
            'aplicada'             => 'Aplicada',
            'pendiente_aprobacion' => 'Pendiente de aprobación',
            'rechazada'            => 'Rechazada',
            default                => $detail['estado'],
        };
    @endphp

    <div class="header">
        {{-- SCRUM-857/858 — marca unificada (isótipo + "ATLANTIC", igual que cotizaciones/
             Estado de Cuenta/Recibo). La nota de crédito es un documento fiscal: nombre legal, RUC
             y dirección del emisor van como bloque de datos fino debajo de la marca. El guion del
             RUC solo si hay dígito verificador. --}}
        <div class="col">
            <div><img class="logo" src="{{ $logoDataUri }}"><span class="brand-name">ATLANTIC</span></div>
            <div class="empresa-datos">
                @if (trim($fiscal->nombre_comercial) !== ''){{ $fiscal->nombre_comercial }}<br>@endif
                @if (trim($fiscal->ruc) !== '')RUC {{ $fiscal->ruc }}{{ trim($fiscal->dv) !== '' ? "-{$fiscal->dv}" : '' }}<br>@endif
                {{ $fiscal->direccion_fiscal }}
            </div>
        </div>
        <div class="col right">
            <div class="nota-numero">Nota de Crédito {{ $detail['numero'] }}</div>
            <div class="nota-fecha">{{ $detail['fecha'] }}</div>
            {{-- RN3 REQ-496 — el estado real siempre visible, nunca aparentando estar aplicada. --}}
            <div class="estado-badge {{ $detail['estado'] }}">{{ $estadoLabel }}</div>
        </div>
    </div>

    <div class="cliente-block">
        <div class="cliente-label">Cliente</div>
        <div class="cliente-nombre">{{ $detail['cliente'] }}</div>
    </div>

    <table class="campos">
        <tr>
            <td class="label">Tipo de nota</td>
            <td class="valor">{{ $tipoLabel }}</td>
        </tr>
        <tr>
            <td class="label">Factura relacionada</td>
            <td class="valor factura-link">{{ $detail['factura_origen_numero'] ?? '—' }}</td>
        </tr>
        @if ($detail['factura_nueva_numero'] ?? null)
            <tr>
                <td class="label">Factura nueva (corrección)</td>
                <td class="valor factura-link">{{ $detail['factura_nueva_numero'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Destino del monto</td>
            <td class="valor">{{ $resultadoLabel }}</td>
        </tr>
        @if ($detail['cuenta_bancaria_salida'] ?? null)
            <tr>
                <td class="label">Cuenta de salida</td>
                <td class="valor">{{ $detail['cuenta_bancaria_salida'] }}</td>
            </tr>
        @endif
        @if ($detail['estado'] === 'rechazada' && ($detail['motivo_rechazo'] ?? null))
            <tr>
                <td class="label">Motivo de rechazo</td>
                <td class="valor">{{ $detail['motivo_rechazo'] }}</td>
            </tr>
        @endif
    </table>

    <div class="totales">
        <div class="fila"><div class="label">Subtotal</div><div class="valor">{{ $moneda }} {{ number_format($detail['subtotal'] ?? 0, 2) }}</div></div>
        <div class="fila"><div class="label">ITBMS</div><div class="valor">{{ $moneda }} {{ number_format($detail['itbms'] ?? 0, 2) }}</div></div>
        <div class="fila total-final"><div class="label">Total</div><div class="valor">{{ $moneda }} {{ number_format($detail['monto'] ?? 0, 2) }}</div></div>
    </div>

    <div class="motivo-block">
        <div class="motivo-label">Motivo</div>
        <div class="empresa-datos">{{ $detail['motivo'] }}</div>
    </div>
</body>
</html>
