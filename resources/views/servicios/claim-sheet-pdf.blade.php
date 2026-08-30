<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    /* REQ-278/279 (SCRUM-781) — mismo isólogo SVG inline ya usado en ventas-diseno/quote-pdf.blade.php
       (compatible con dompdf, sin depender de un asset externo/base64). */
    .brand-row { display: table; width: 100%; }
    .brand-cell { display: table-cell; vertical-align: middle; }
    .brand { font-size: 18px; font-weight: bold; color: #5BA5A0; padding-left: 8px; }
    .sub { color: #6b7280; font-size: 10px; }

    .section-title { font-size: 12px; font-weight: bold; color: #ffffff; background: #5BA5A0; padding: 4px 8px; margin-bottom: 8px; text-transform: uppercase; }
    .section { margin-bottom: 16px; page-break-inside: avoid; }
    .label { font-size: 9px; font-weight: bold; text-transform: uppercase; color: #3D7E7A; margin-bottom: 2px; }
    .value { font-size: 11px; margin-bottom: 8px; }

    table.print-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.print-table th { background: #E3F0EE; color: #3D7E7A; text-align: left; padding: 4px 6px; font-size: 9px; text-transform: uppercase; }
    table.print-table td { padding: 4px 6px; border-bottom: 1px solid #f0f0f0; font-size: 10px; }

    .empty-note { color: #9ca3af; font-style: italic; }

    .signatures { display: table; width: 100%; margin-top: 28px; }
    .signature-box { display: table-cell; width: 50%; text-align: center; padding-top: 32px; border-top: 1px solid #2a2520; }

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
        <div class="sub">Hoja de Reclamo — Ticket {{ $ticket->numero }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="section-title">1. Datos del reclamante</div>
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
        <div class="label">Email</div>
        <div class="value">{{ $detail['email'] ?? '—' }}</div>
        <div class="label">Dirección</div>
        <div class="value">{{ $detail['direccion'] ?? '—' }}</div>
        <div class="label">Proyecto</div>
        <div class="value">{{ $detail['proyecto'] ?? '—' }}</div>
        <div class="label">Responsable</div>
        <div class="value">{{ $ticket->internalTechnician ? $ticket->internalTechnician->first_name.' '.$ticket->internalTechnician->last_name : '—' }}</div>
    </div>

    <div class="section">
        <div class="section-title">2. Producto(s) reclamado(s)</div>
        @if(!empty($detail['productos']))
            <table class="print-table">
                <thead>
                    <tr><th>Marca</th><th>Referencia</th><th>Descripción</th><th>Cant. reclamo</th><th>Cant. recibida</th><th>Cant. pendiente</th></tr>
                </thead>
                <tbody>
                    @foreach($detail['productos'] as $producto)
                        <tr>
                            <td>{{ $producto['marca'] ?? '—' }}</td>
                            <td>{{ $producto['referencia'] ?? '—' }}</td>
                            <td>{{ $producto['descripcion'] ?? '—' }}</td>
                            <td>{{ $producto['cantidad_reclamada'] ?? '—' }}</td>
                            <td>{{ $producto['cantidad_recibida'] ?? '—' }}</td>
                            <td>{{ $producto['cantidad_pendiente'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">Sin productos registrados</p>
        @endif
    </div>

    <div class="section">
        <div class="section-title">3. Descripción del problema y diagnóstico</div>
        <div class="label">Fecha del reclamo</div>
        <div class="value">{{ $sheet->fecha_reclamo?->format('d/m/Y') ?? '—' }}</div>
        <div class="label">Descripción del problema</div>
        <div class="value">{{ $sheet->descripcion_problema ?: '—' }}</div>
        <div class="label">Diagnóstico</div>
        <div class="value">{{ $diagnosticoLabel }}</div>
    </div>

    <div class="section">
        <div class="section-title">4. Firma del responsable</div>
        <div class="signatures">
            <div class="signature-box">{{ $sheet->firma_responsable }}<br><span class="sub">Responsable</span></div>
        </div>
    </div>

    <div class="footer">
        Illuminations · Panamá · Documento generado automáticamente desde el sistema.
    </div>
</body>
</html>
