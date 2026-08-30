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
    .no-cost-note { margin-top: 20px; font-size: 9px; color: #9ca3af; font-style: italic; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">ATLANTIC</div>
        <div class="sub">Powered by AtlanticERP</div>
    </div>

    <div class="title">Orden de Compra #{{ $order->id }}</div>

    <table class="meta">
        <tr>
            <td class="label">Proveedor</td>
            <td class="label">Estado</td>
            <td class="label">Modalidad</td>
        </tr>
        <tr>
            <td>{{ $order->provider->name }}</td>
            <td>{{ $statusLabel }}</td>
            <td>{{ $order->modality === 'zona_libre' ? 'Zona Libre' : 'Directo' }}</td>
        </tr>
        <tr>
            <td class="label">Tipo de envío</td>
            <td class="label">Llegada estimada</td>
            <td class="label">Fecha de creación</td>
        </tr>
        <tr>
            <td>{{ $shippingTypeLabel }}</td>
            <td>{{ $order->estimated_arrival_date?->format('d/m/Y') ?? '—' }}</td>
            <td>{{ $order->created_at->format('d/m/Y') }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Referencia</th>
                <th>Descripción</th>
                <th>Cantidad</th>
                @if($includeCost)
                    <th>Costo unitario</th>
                    <th>Subtotal</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($order->lines as $line)
                <tr>
                    <td>{{ $line->catalogProduct?->reference }}</td>
                    <td>{{ $line->catalogProduct?->name }}</td>
                    <td>{{ $line->quantity }}</td>
                    @if($includeCost)
                        <td>${{ number_format($line->unit_cost, 2) }}</td>
                        <td>${{ number_format($line->subtotal, 2) }}</td>
                    @endif
                </tr>
            @endforeach
            @if($includeCost)
                <tr class="total-row">
                    <td colspan="4">Total</td>
                    <td>${{ number_format($order->total_amount, 2) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    @if(!$includeCost)
        <p class="no-cost-note">Documento sin información de costos.</p>
    @endif
</body>
</html>
