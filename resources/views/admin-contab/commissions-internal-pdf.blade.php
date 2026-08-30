<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    .brand-name { font-size: 15px; font-weight: bold; color: #2a2520; }
    .brand-line { border-bottom: 2px solid #5BA5A0; margin: 6px 0 14px; }
    h1 { font-size: 14px; color: #3D7E7A; margin: 0 0 4px; }
    .sub { font-size: 10px; color: #6b7280; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 5px; font-size: 9px; text-transform: uppercase; }
    th.numeric, td.numeric { text-align: right; }
    td { padding: 5px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
</style>
</head>
<body>
    <div class="brand-name">ILLUMINATIONS</div>
    <div class="brand-line"></div>
    <h1>Comisiones Internas — {{ $data['mes'] }}</h1>
    <div class="sub">Comisión escalonada por vendedor, calculada sobre el total de pedidos cerrados en el mes · USD</div>

    <table>
        <thead>
            <tr>
                <th>Vendedor</th>
                <th class="numeric">Total pedidos</th>
                <th class="numeric">% comisión</th>
                <th class="numeric">Ya pagada</th>
                <th class="numeric">Por pagar</th>
                <th class="numeric">Pendiente de cobro</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['vendedores'] as $v)
            <tr>
                <td>{{ $v['vendedor_nombre'] }}</td>
                <td class="numeric">${{ number_format($v['total_pedidos_mes'], 2) }}</td>
                <td class="numeric">{{ $v['porcentaje'] }}%{{ $v['porcentaje_fijo'] ? '' : ' (provisional)' }}</td>
                <td class="numeric">${{ number_format($v['pagada'], 2) }}</td>
                <td class="numeric">${{ number_format($v['por_pagar'], 2) }}</td>
                <td class="numeric">${{ number_format($v['pendiente_cobro'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
