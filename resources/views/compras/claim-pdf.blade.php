<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 18px; font-weight: bold; color: #5BA5A0; }
    .sub { color: #6b7280; font-size: 10px; }
    .title { font-size: 14px; font-weight: bold; margin-top: 12px; }
    table.meta { width: 100%; margin-bottom: 16px; }
    table.meta td { padding: 2px 0; vertical-align: top; }
    table.meta .label { color: #6b7280; font-size: 9px; text-transform: uppercase; width: 33%; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; }
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
    .total-row td { font-weight: bold; border-top: 2px solid #5BA5A0; padding-top: 8px; }
    .resolution { margin-top: 16px; font-size: 10px; }
    .resolution .label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">ATLANTIC</div>
        <div class="sub">Powered by AtlanticERP</div>
    </div>

    <div class="title">Reclamo {{ $claim->code() }}</div>

    <table class="meta">
        <tr>
            <td class="label">Orden asociada</td>
            <td class="label">Proveedor</td>
            <td class="label">Estado</td>
        </tr>
        <tr>
            <td>#{{ $claim->purchase_order_id }}</td>
            <td>{{ $claim->purchaseOrder->provider->name }}</td>
            <td>{{ $claim->status === 'resuelto' ? 'Resuelto' : 'En revisión' }}</td>
        </tr>
        <tr>
            <td class="label">Fecha de creación</td>
            <td class="label">Fecha de resolución</td>
            <td></td>
        </tr>
        <tr>
            <td>{{ $claim->created_at->format('d/m/Y') }}</td>
            <td>{{ $claim->resolved_at?->format('d/m/Y') ?? '—' }}</td>
            <td></td>
        </tr>
    </table>

    @if($claim->description)
        <p><strong>Descripción:</strong> {{ $claim->description }}</p>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Cantidad afectada</th>
                <th>Monto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($claim->lines as $line)
                <tr>
                    <td>{{ $line->orderLine?->catalogProduct?->name }}</td>
                    <td>{{ $line->affected_quantity }}</td>
                    <td>${{ number_format($line->subtotal(), 2) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="2">Total</td>
                <td>${{ number_format($claim->totalAmount(), 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if($claim->expected_resolution)
        <div class="resolution">
            <div class="label">Resolución esperada</div>
            <p>{{ $claim->expected_resolution }}</p>
        </div>
    @endif
</body>
</html>
