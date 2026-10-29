<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    {{-- SCRUM-886/SCRUM-893 — unificado con el modelo `servicios/service-quote-pdf.blade.php`:
         logo real (antes solo texto teal + "Powered by AtlanticERP"), encabezado/pie reales fijos
         (antes ninguno de los dos), labels de sección en gris (convención `.block-title` del
         modelo, ver `pdf.partials.styles`). --}}
    @page { margin: 95px 40px 60px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; margin: 0; }
    @include('pdf.partials.styles')

    .title { font-size: 14px; font-weight: bold; margin-top: 4px; margin-bottom: 12px; }
    table.meta { width: 100%; margin-bottom: 16px; }
    table.meta td { padding: 2px 0; vertical-align: top; }
    table.meta .label { color: #6b7280; font-size: 9px; text-transform: uppercase; width: 33%; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.lines th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; }
    table.lines td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
    .no-cost-note { margin-top: 20px; font-size: 9px; color: #9ca3af; font-style: italic; }

    {{-- SCRUM-886 (rebote 2026-09-14) — Total movido a tabla propia alineada a la derecha, mismo
         patrón `table.totals` que `servicios/service-quote-pdf.blade.php`/`ventas-diseno/quote-pdf.blade.php`
         (antes era una fila del propio `table.lines` con colspan, por eso "Total" quedaba pegado al
         borde izquierdo y el valor al derecho, ocupando el ancho completo de la página). --}}
    table.totals { width: auto; margin: 12px 0 0 auto; border-collapse: collapse; border-spacing: 0; }
    table.totals td { padding: 2px 0; font-size: 11px; }
    table.totals td:first-child { padding-right: 18px; }
    table.totals td.numeric { text-align: right; }
    table.totals tr.grand-total td { font-size: 13px; font-weight: bold; border-top: 2px solid #5BA5A0; padding-top: 8px; }
</style>
</head>
<body>
    @php($lang = $lang ?? 'es')
    @php($descriptions = $descriptions ?? [])
    @php($t = fn (string $key, array $replace = []) => trans('purchase_order_pdf.'.$key, $replace, $lang))
    @include('pdf.partials.header')

    <div class="title">{{ $t('title', ['id' => $order->id]) }}</div>

    <table class="meta">
        <tr>
            <td class="label">{{ $t('provider') }}</td>
            <td class="label">{{ $t('status') }}</td>
            <td class="label">{{ $t('modality') }}</td>
        </tr>
        <tr>
            <td>{{ $order->provider->name }}</td>
            <td>{{ $statusLabel }}</td>
            <td>{{ $order->modality === 'zona_libre' ? $t('modality_zona_libre') : $t('modality_direct') }}</td>
        </tr>
        <tr>
            <td class="label">{{ $t('shipping_type') }}</td>
            <td class="label">{{ $t('estimated_arrival') }}</td>
            <td class="label">{{ $t('created_at') }}</td>
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
                {{-- SCRUM-1123 — Referencia de fábrica, inmediatamente a la izquierda de Referencia; se lee del producto
                     al generar, así que aplica también a órdenes previas sin editarlas. --}}
                <th>{{ $t('factory_reference') }}</th>
                <th>{{ $t('reference') }}</th>
                <th>{{ $t('description') }}</th>
                <th>{{ $t('quantity') }}</th>
                @if($includeCost)
                    <th>{{ $t('unit_cost') }}</th>
                    <th>{{ $t('subtotal') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($order->lines as $line)
                <tr>
                    <td>{{ $line->catalogProduct?->factory_reference }}</td>
                    <td>{{ $line->catalogProduct?->reference }}</td>
                    <td>{{ $descriptions[$line->id] ?? ($line->is_adjustment ? $line->adjustment_note : $line->catalogProduct?->name) }}</td>
                    <td>{{ $line->quantity }}</td>
                    @if($includeCost)
                        <td>${{ number_format($line->unit_cost, 2) }}</td>
                        <td>${{ number_format($line->subtotal, 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($includeCost)
        <table class="totals">
            <tr class="grand-total">
                <td>{{ $t('total') }}</td>
                <td class="numeric">${{ number_format($order->total_amount, 2) }}</td>
            </tr>
        </table>
    @else
        <p class="no-cost-note">{{ $t('no_cost_note') }}</p>
    @endif

    @include('pdf.partials.footer')
</body>
</html>
