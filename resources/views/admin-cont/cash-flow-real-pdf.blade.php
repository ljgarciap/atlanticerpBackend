<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    h1 { font-size: 15px; color: #2a2520; margin: 0 0 4px 0; }
    .subtitle { color: #6b7280; font-size: 10px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    th.numeric, td.numeric { text-align: right; }
    td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .empty { color: #9ca3af; text-align: center; padding: 16px 0; }
</style>
</head>
<body>
    <h1>Flujo de caja — Real (histórico)</h1>
    <div class="subtitle">Últimos {{ $window_days }} días · Saldo actual ${{ number_format($saldo_actual, 2) }} · Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>

    @if (count($movimientos) === 0)
        <div class="empty">Sin movimientos en esta ventana.</div>
    @else
        <table>
            <thead>
                <tr><th>Fecha</th><th>Concepto</th><th>Origen</th><th class="numeric">Entrada</th><th class="numeric">Salida</th><th class="numeric">Saldo acumulado</th></tr>
            </thead>
            <tbody>
                @foreach ($movimientos as $m)
                    <tr>
                        <td>{{ $m['fecha'] }}</td>
                        <td>{{ $m['concepto'] }}</td>
                        <td>{{ $m['origen'] }}</td>
                        <td class="numeric">{{ $m['entrada'] > 0 ? '$'.number_format($m['entrada'], 2) : '—' }}</td>
                        <td class="numeric">{{ $m['salida'] > 0 ? '$'.number_format($m['salida'], 2) : '—' }}</td>
                        <td class="numeric">${{ number_format($m['saldo_acumulado'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
