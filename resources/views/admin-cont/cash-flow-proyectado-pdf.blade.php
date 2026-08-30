<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    h1 { font-size: 15px; color: #2a2520; margin: 0 0 4px 0; }
    h2 { font-size: 11px; color: #3D7E7A; text-transform: uppercase; margin: 16px 0 6px 0; }
    .subtitle { color: #6b7280; font-size: 10px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
    th.numeric, td.numeric { text-align: right; }
    td { padding: 5px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    .empty { color: #9ca3af; text-align: center; padding: 16px 0; }
    .totals { margin-top: 16px; }
    .totals td { border: none; font-weight: bold; }
</style>
</head>
<body>
    <h1>Flujo de caja — Proyectado</h1>
    <div class="subtitle">Ventana: {{ $window_days === 0 ? 'Hoy' : $window_days.' días' }} · Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>

    <h2>Entradas esperadas (Facturación)</h2>
    @if (count($entradas) === 0)
        <div class="empty">Sin entradas esperadas en esta ventana.</div>
    @else
        <table>
            <thead><tr><th>Concepto</th><th>Referencia</th><th class="numeric">Monto</th></tr></thead>
            <tbody>
                @foreach ($entradas as $e)
                    <tr><td>{{ $e['nombre'] }}</td><td>{{ $e['referencia'] }}</td><td class="numeric">${{ number_format($e['monto'], 2) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Salidas esperadas (Comisiones + Notas crédito)</h2>
    @if (count($salidas) === 0)
        <div class="empty">Sin salidas esperadas en esta ventana.</div>
    @else
        <table>
            <thead><tr><th>Concepto</th><th>Referencia</th><th class="numeric">Monto</th></tr></thead>
            <tbody>
                @foreach ($salidas as $s)
                    <tr><td>{{ $s['nombre'] }}</td><td>{{ $s['referencia'] }}</td><td class="numeric">${{ number_format($s['monto'], 2) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr><td>Total entradas</td><td class="numeric">${{ number_format($total_entradas, 2) }}</td></tr>
        <tr><td>Total salidas</td><td class="numeric">-${{ number_format($total_salidas, 2) }}</td></tr>
        <tr><td>Neto proyectado</td><td class="numeric">${{ number_format($neto, 2) }}</td></tr>
    </table>
</body>
</html>
