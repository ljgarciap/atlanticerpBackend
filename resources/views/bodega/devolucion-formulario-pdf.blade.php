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
    .signature { margin-top: 40px; border-top: 1px solid #2a2520; width: 260px; padding-top: 6px; font-size: 9px; }
    .signature .name { font-family: 'Times New Roman', serif; font-style: italic; font-size: 14px; margin-bottom: 4px; }
</style>
</head>
<body>
    @php
        $reasonLabels = [
            'danado_defectuoso' => 'Producto dañado/defectuoso',
            'no_corresponde' => 'No corresponde a lo pedido',
            'cliente_cambio_opinion' => 'Cliente cambió de opinión',
            'excedente' => 'Excedente — sobró material',
            'error_instalacion' => 'Error en la instalación/medidas',
            'otra' => 'Otro',
        ];
        $reasonLabel = function (?string $reason) use ($reasonLabels): string {
            if ($reason === null) {
                return '—';
            }
            [$code, $freeText] = array_pad(explode(':', $reason, 2), 2, null);
            $label = $reasonLabels[$code] ?? $code;
            return $freeText !== null ? "{$label}: {$freeText}" : $label;
        };
    @endphp

    <div class="header">
        <div class="brand">ILLUMINATIONS</div>
        <div class="sub">Formulario de Devolución</div>
    </div>

    <div class="title">Devolución {{ $customerReturn->return_number }}</div>

    <table class="meta">
        <tr>
            <td class="label">N° de devolución</td>
            <td class="label">Fecha</td>
            <td class="label">Pedido origen</td>
        </tr>
        <tr>
            <td>{{ $customerReturn->return_number }}</td>
            <td>{{ $customerReturn->created_at->format('d/m/Y') }}</td>
            <td>{{ $customerReturn->order?->order_number }}</td>
        </tr>
        <tr>
            <td class="label">Cliente</td>
            <td class="label" colspan="2">Contacto que entrega el producto</td>
        </tr>
        <tr>
            <td>{{ $customerReturn->order?->customer_name ?? '—' }}</td>
            <td colspan="2">{{ $customerReturn->contact_name }} — {{ $customerReturn->contact_phone }}</td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Ref. pública</th>
                <th>Artículo</th>
                <th>Cantidad</th>
                <th>Motivo</th>
            </tr>
        </thead>
        <tbody>
            @foreach($customerReturn->lines as $line)
                <tr>
                    <td>{{ $line->reference_snapshot ?? $line->orderItem?->catalogProduct?->reference ?? '—' }}</td>
                    <td>{{ $line->description_snapshot ?? $line->orderItem?->catalogProduct?->name ?? '—' }}</td>
                    <td>{{ $line->qty_received ?? $line->qty_requested }}</td>
                    <td>{{ $reasonLabel($line->reason) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p><strong>Estado:</strong> {{ $customerReturn->status }}</p>

    <div class="signature">
        @if($customerReturn->hasSignedDocument())
            <div class="name">{{ $customerReturn->contact_name }}</div>
        @endif
        Firma de quien entrega
    </div>
</body>
</html>
