<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    /* REQ-235 (SCRUM-781, punto 9) — mismo isólogo SVG inline ya usado en
       ventas-diseno/quote-pdf.blade.php (compatible con dompdf, sin depender de un asset
       externo/base64). */
    .brand-row { display: table; width: 100%; }
    .brand-cell { display: table-cell; vertical-align: middle; }
    .brand { font-size: 18px; font-weight: bold; color: #5BA5A0; padding-left: 8px; }
    .sub { color: #6b7280; font-size: 10px; }

    .section { margin-bottom: 14px; page-break-inside: avoid; }
    .label { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #3D7E7A; margin-bottom: 2px; }
    .value { font-size: 11px; margin-bottom: 8px; }

    table.print-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.print-table th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 6px; font-size: 9px; text-transform: uppercase; }
    table.print-table td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }
    table.print-table td.numeric, table.print-table th.numeric { text-align: right; }

    .totals { width: 100%; margin-top: 6px; }
    .totals td { padding: 3px 6px; font-size: 11px; }
    .totals td.label { text-align: right; color: #3D7E7A; font-weight: bold; text-transform: none; }
    .totals td.numeric { text-align: right; width: 90px; }
    .totals tr.grand-total td { font-size: 13px; font-weight: bold; color: #2a2520; border-top: 2px solid #5BA5A0; padding-top: 6px; }

    .conditions { white-space: pre-line; font-size: 10px; color: #2a2520; }

    .empty-note { color: #9ca3af; font-style: italic; }

    .signatures { display: table; width: 100%; margin-top: 28px; }
    .signature-box { display: table-cell; width: 50%; text-align: center; padding-top: 32px; border-top: 1px solid #2a2520; }
    .signature-box.signed { padding-top: 4px; border-top: none; }
    .signed-mark { font-size: 12px; font-weight: bold; color: #5BA5A0; margin-bottom: 4px; }

    .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #E3F0EE; font-size: 8px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 28px;">
                <img src="{{ $logoDataUri }}" width="26" height="26" alt="Illuminations">
            </div>
            <div class="brand-cell brand">ILLUMINATIONS</div>
        </div>
        <div class="sub">Cotización de Servicio {{ $quote->numero }} — Ticket {{ $ticket->numero }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="label">Cliente</div>
        <div class="value">
            @if($detail['cliente_master'] === $detail['subcliente'])
                {{ $detail['cliente_master'] ?? '—' }}
            @else
                {{ $detail['cliente_master'] ?? '—' }} — {{ $detail['subcliente'] ?? '—' }}
            @endif
        </div>

        <div class="label">Contacto</div>
        <div class="value">{{ $detail['contacto'] ?? '—' }}{{ $detail['telefono'] ? ' · '.$detail['telefono'] : '' }}</div>

        <div class="label">Dirección</div>
        <div class="value">{{ $detail['direccion'] ?? '—' }}</div>

        <div class="label">Proyecto asociado</div>
        <div class="value">{{ $detail['proyecto'] ?? 'Sin proyecto asociado' }}</div>
    </div>

    <div class="section">
        <div class="label">Ítems cotizados</div>
        @if(!empty($items))
            <table class="print-table">
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th class="numeric">Cantidad</th>
                        <th class="numeric">Precio unitario</th>
                        <th class="numeric">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        <tr>
                            <td>{{ $item['description'] }}</td>
                            <td class="numeric">{{ number_format($item['quantity'], 2) }}</td>
                            <td class="numeric">${{ number_format($item['unit_price'], 2) }}</td>
                            <td class="numeric">${{ number_format($item['subtotal'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">Sin ítems registrados</p>
        @endif

        <table class="totals">
            <tr>
                <td class="label">Subtotal</td>
                <td class="numeric">${{ number_format($quote->subtotal, 2) }}</td>
            </tr>
            <tr>
                <td class="label">Descuento ({{ number_format($quote->discount_percent, 2) }}%)</td>
                <td class="numeric">
                    {{-- SCRUM-298 (rebote QA 2026-08-13) — antes mostraba siempre "—", sin importar
                         el % real, obligando al cliente a calcular el monto a mano. --}}
                    @if($quote->discount_percent > 0)
                        -${{ number_format($quote->subtotal * $quote->discount_percent / 100, 2) }}
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label">ITBMS</td>
                <td class="numeric">${{ number_format($quote->itbms, 2) }}</td>
            </tr>
            <tr class="grand-total">
                <td class="label">Total</td>
                <td class="numeric">${{ number_format($quote->total, 2) }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <div class="label">Observaciones</div>
        <div class="value">{{ $quote->observations ?: '—' }}</div>
    </div>

    {{-- SCRUM-298 (rebote QA 2026-08-13, RN1 REQ-235) — faltaba por completo esta sección; el RN1
         del ticket la lista como contenido obligatorio del documento. --}}
    <div class="section">
        <div class="label">Información de pago</div>
        <div class="value">Cheque a nombre de: Illuminations<br>Pagos ACH: consultar con Servicios</div>
    </div>

    <div class="section">
        <div class="label">Condiciones del servicio</div>
        <div class="conditions value">{{ $conditions }}</div>
    </div>

    <div class="signatures">
        <div class="signature-box {{ $firmada ? 'signed' : '' }}">
            @if($firmada)
                <div class="signed-mark">&#10003; Firmado</div>
                {{ $detail['contacto'] ?? ($detail['cliente_master'] ?? 'Cliente') }}<br>
            @endif
            <span class="sub">Cliente</span>
        </div>
    </div>

    <div class="footer">
        Illuminations · Panamá · Documento generado automáticamente desde el sistema.
    </div>
</body>
</html>
