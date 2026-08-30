<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    .brand { font-size: 18px; font-weight: bold; color: #5BA5A0; }
    .sub { color: #6b7280; font-size: 10px; }
    .title { font-size: 14px; font-weight: bold; margin-top: 12px; margin-bottom: 12px; }

    .card { width: 100%; border: 1px solid #E3F0EE; border-radius: 4px; margin-bottom: 14px; page-break-inside: avoid; }
    .card table.layout { width: 100%; border-collapse: collapse; }
    .card .photo-cell { width: 32%; padding: 10px; vertical-align: top; text-align: center; }
    .card .photo-cell img { max-width: 100%; max-height: 120px; }
    .card .photo-placeholder {
        width: 100%; height: 100px; background: #f7f5ef; border: 1px dashed #d1d5db;
        color: #9ca3af; font-size: 9px; text-align: center; vertical-align: middle;
    }
    .card .data-cell { padding: 10px 12px 10px 0; vertical-align: top; }
    .card .reference { font-size: 12px; font-weight: bold; color: #3D7E7A; }
    .card .brand-desc { color: #2a2520; margin: 2px 0 6px 0; }
    .card .price { font-size: 13px; font-weight: bold; color: #9fc54d; margin-bottom: 6px; }

    table.specs { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.specs th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 3px 6px; font-size: 8px; text-transform: uppercase; }
    table.specs td { padding: 3px 6px; border-bottom: 1px solid #f0f0f0; font-size: 9px; }

    .empty-note { color: #9ca3af; font-style: italic; margin-top: 20px; }

    .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #E3F0EE; font-size: 8px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">ILLUMINATIONS</div>
        <div class="sub">Catálogo — generado el {{ $generatedAt->format('d/m/Y') }}</div>
    </div>

    <div class="title">Catálogo de productos</div>

    @forelse($products as $product)
        <div class="card">
            <table class="layout">
                <tr>
                    <td class="photo-cell">
                        @if(!empty($product['photo_url']))
                            <img src="{{ $product['photo_url'] }}" alt="{{ $product['reference'] }}">
                        @else
                            <table class="photo-placeholder" width="100%"><tr><td>Sin imagen</td></tr></table>
                        @endif
                    </td>
                    <td class="data-cell">
                        <div class="reference">{{ $product['reference'] }}</div>
                        <div class="brand-desc">
                            @if(!empty($product['brand']))
                                {{ $product['brand'] }} —
                            @endif
                            {{ $product['name'] }}
                        </div>
                        <div class="price">${{ number_format($product['price_full'], 2) }}</div>

                        @if(!empty($product['specs']))
                            <table class="specs">
                                <thead>
                                    <tr><th>Especificación</th><th>Valor</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($product['specs'] as $spec)
                                        <tr>
                                            <td>{{ $spec['label'] }}</td>
                                            <td>{{ $spec['value'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    @empty
        <p class="empty-note">No hay productos para incluir en este catálogo.</p>
    @endforelse

    <div class="footer">
        Illuminations · Panamá · Documento generado automáticamente desde el Catálogo — precios sujetos a cambio sin previo aviso.
    </div>
</body>
</html>
