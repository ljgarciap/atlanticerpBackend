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
    .titulo { font-size: 14px; font-weight: bold; }
    .reporte-numero { font-size: 16px; font-weight: bold; color: #3D7E7A; }
    .reporte-fecha { color: #6b7280; font-size: 9.5px; }
    .estado-badge {
        display: inline-block; margin-top: 4px; padding: 2px 8px; border-radius: 10px;
        font-size: 9px; font-weight: bold; text-transform: uppercase;
    }
    .estado-badge.finalizado { background: #E3F0EE; color: #3D7E7A; }
    .estado-badge.pendiente_aprobacion { background: #fef3c7; color: #92400e; }
    .estado-badge.rechazado { background: #fee2e2; color: #9a3f30; }
    .campos { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .campos td { padding: 5px 0; font-size: 10.5px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    .campos td.label { color: #6b7280; width: 180px; }
    table.lineas { width: 100%; border-collapse: collapse; margin-top: 14px; }
    table.lineas th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 4px 6px; }
    table.lineas td { font-size: 10px; padding: 4px 6px; border-bottom: 1px solid #f5f5f5; }
    table.lineas td.num { text-align: right; }
    tr.grupo td { background: #f7f5ef; font-weight: bold; padding-top: 8px; }
    tr.subtotal td { font-weight: bold; border-top: 1px solid #e5e7eb; }
    .total-general { width: 260px; margin-left: auto; margin-top: 14px; display: table; }
    .total-general .fila { display: table-row; }
    .total-general .fila .label, .total-general .fila .valor { display: table-cell; padding: 3px 0; font-size: 12.5px; font-weight: bold; color: #3D7E7A; }
    .total-general .fila .valor { text-align: right; }
</style>
</head>
<body>
    @php
        $estadoLabel = match ($estado) {
            'finalizado' => 'Finalizado',
            'rechazado'  => 'Rechazado',
            default      => 'Pendiente de aprobación',
        };
    @endphp
    <div class="header">
        <div class="col">
            <div class="titulo">Atlantic — Reporte de Caja Chica</div>
        </div>
        <div class="col right">
            <div class="reporte-numero">{{ $numero }}</div>
            <div class="reporte-fecha">{{ $fecha_creacion }}</div>
            <span class="estado-badge {{ $estado }}">{{ $estadoLabel }}</span>
        </div>
    </div>

    <table class="campos">
        <tr><td class="label">Forma de pago</td><td>{{ ucfirst($forma_pago) }}</td></tr>
        <tr><td class="label">Realizado por</td><td>{{ $realizado_por_nombre ?? '—' }}</td></tr>
        @if ($estado === 'finalizado')
            <tr><td class="label">Aprobado por</td><td>{{ $aprobado_por_nombre ?? '—' }}</td></tr>
            <tr><td class="label">Fecha de aprobación</td><td>{{ $fecha_aprobacion ?? '—' }}</td></tr>
        @endif
    </table>

    <table class="lineas">
        <thead>
            <tr><th>Fecha</th><th>Proveedor</th><th>Descripción</th><th class="num">M. Bruto</th><th class="num">ITBMS</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
        @foreach ($grupos as $grupo)
            <tr class="grupo"><td colspan="6">{{ $grupo['solicitante_nombre'] }}</td></tr>
            @foreach ($grupo['lineas'] as $linea)
                <tr>
                    <td>{{ $linea['fecha'] }}</td>
                    <td>{{ $linea['proveedor'] }}</td>
                    <td>{{ $linea['descripcion'] }}</td>
                    <td class="num">${{ number_format($linea['monto_bruto'], 2) }}</td>
                    <td class="num">${{ number_format($linea['itbms'], 2) }}</td>
                    <td class="num">${{ number_format($linea['total'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="subtotal"><td colspan="5">Subtotal — {{ $grupo['solicitante_nombre'] }}</td><td class="num">${{ number_format($grupo['subtotal'], 2) }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <div class="total-general">
        <div class="fila"><div class="label">Total general</div><div class="valor">${{ number_format($total_general, 2) }}</div></div>
    </div>
</body>
</html>
