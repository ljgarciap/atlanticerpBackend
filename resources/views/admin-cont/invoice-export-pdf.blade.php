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
    <h1>Facturación — Entregas y facturas</h1>
    <div class="subtitle">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>

    @if (count($rows) === 0)
        <div class="empty">Sin entregas para los filtros seleccionados.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Entrega / Hito</th>
                    <th>Cliente</th>
                    <th>Vendedor</th>
                    <th>N° Factura</th>
                    <th>N° Cotización</th>
                    <th class="numeric">Monto</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row[0] }}</td>
                        <td>{{ $row[1] }}</td>
                        <td>{{ $row[2] }}</td>
                        <td>{{ $row[3] }}</td>
                        <td>{{ $row[4] }}</td>
                        <td class="numeric">${{ $row[5] }}</td>
                        <td>{{ $row[6] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
