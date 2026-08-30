<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    h1 { font-size: 15px; color: #2a2520; margin: 0 0 4px 0; }
    h2 { font-size: 12px; color: #3D7E7A; margin: 18px 0 6px 0; }
    .subtitle { color: #6b7280; font-size: 10px; margin-bottom: 16px; }
    .totales { margin-bottom: 12px; }
    .totales td { padding: 3px 10px 3px 0; font-size: 11px; }
    .totales td.label { color: #6b7280; }
    .totales td.value { text-align: right; font-weight: bold; }
    table.rows { width: 100%; border-collapse: collapse; }
    th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    th.numeric, td.numeric { text-align: right; }
    td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .empty { color: #9ca3af; padding: 6px 0; }
    .estado-pendiente { color: #92600f; }
    .estado-ok { color: #3D7E7A; }
</style>
</head>
<body>
    <h1>Arqueo N° {{ $numero ?? '—' }}</h1>
    <div class="subtitle">
        Fecha {{ $fecha }} · Generado el {{ $generatedAt->format('d/m/Y H:i') }}
        @if ($aprobado_por)
            · Aprobado por {{ $aprobado_por }} el {{ \Carbon\Carbon::parse($aprobado_at)->format('d/m/Y H:i') }}
        @elseif ($cerrado_por)
            · Pendiente de aprobación de Gerencia (Mark)
        @else
            · Arqueo aún no cerrado
        @endif
    </div>

    <table class="totales">
        <tr><td class="label">Total cobrado</td><td class="value">${{ number_format($total_cobrado, 2) }}</td></tr>
        <tr><td class="label">Total notas de crédito</td><td class="value">${{ number_format($total_notas_credito, 2) }}</td></tr>
        <tr><td class="label">Total neto</td><td class="value">${{ number_format($total_neto, 2) }}</td></tr>
    </table>

    @if (!empty($observacion_general))
        <h2>Observación general</h2>
        <div>{{ $observacion_general }}</div>
    @endif

    @if (!empty($retenciones))
        <h2>Ajustes / Retención</h2>
        <table class="rows">
            <thead><tr><th>Cliente</th><th>Motivo</th><th class="numeric">Monto</th><th>Constancia</th></tr></thead>
            <tbody>
                @foreach ($retenciones as $r)
                    <tr>
                        <td>{{ $r['cliente'] ?? '—' }}</td>
                        <td>{{ $r['motivo'] ?? '—' }}</td>
                        <td class="numeric">${{ number_format($r['monto'], 2) }}</td>
                        <td class="{{ $r['constancia'] ? 'estado-ok' : 'estado-pendiente' }}">
                            {{ $r['constancia'] ? '✓ '.$r['constancia']['nombre_archivo'] : 'Pendiente' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Cobros del día</h2>
    @if (empty($cobros))
        <div class="empty">Sin cobros este día.</div>
    @else
        <table class="rows">
            <thead><tr><th>Concepto</th><th>Método</th><th class="numeric">Monto</th></tr></thead>
            <tbody>
                @foreach ($cobros as $c)
                    <tr>
                        <td>{{ $c['concepto'] }}</td>
                        <td>{{ $c['metodo_pago'] }}</td>
                        <td class="numeric">${{ number_format($c['monto'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (!empty($notas_credito))
        <h2>Notas de crédito devueltas</h2>
        <table class="rows">
            <thead><tr><th>Concepto</th><th class="numeric">Monto</th></tr></thead>
            <tbody>
                @foreach ($notas_credito as $n)
                    <tr>
                        <td>{{ $n['concepto'] }}</td>
                        <td class="numeric">${{ number_format($n['monto'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
