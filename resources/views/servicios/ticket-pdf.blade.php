<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    /* REQ-228 (SCRUM-781) — mismo isólogo embebido que claim-sheet-pdf/inspection-report-pdf/
       service-quote-pdf.blade.php (dompdf no renderiza <svg> inline, ver PdfLogo::dataUri()). */
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

    .empty-note { color: #9ca3af; font-style: italic; }

    .footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #E3F0EE; font-size: 8px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand-row">
            <div class="brand-cell" style="width: 28px;">
                <img src="{{ $logoDataUri }}" width="26" height="26" alt="Atlantic">
            </div>
            <div class="brand-cell brand">ATLANTIC</div>
        </div>
        <div class="sub">Ticket de Servicio {{ $ticket->numero }} — {{ $tipoLabel }}{{ $subtipoLabel ? ' — '.$subtipoLabel : '' }}</div>
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

        <div class="label">Email de contacto</div>
        <div class="value">{{ $detail['email'] ?? '—' }}</div>

        <div class="label">Proyecto asociado</div>
        <div class="value">{{ $detail['proyecto'] ?? 'Sin proyecto asociado' }}</div>

        <div class="label">Contacto</div>
        <div class="value">{{ $detail['contacto'] ?? '—' }}{{ $detail['telefono'] ? ' · '.$detail['telefono'] : '' }}</div>

        <div class="label">Dirección completa</div>
        <div class="value">{{ $detail['direccion'] ?? '—' }}</div>

        <div class="label">Fecha y hora de servicio</div>
        <div class="value">{{ $fechaServicio }}</div>

        <div class="label">Horario de trabajo / visita</div>
        <div class="value">{{ $horario }}</div>
    </div>

    <div class="section">
        <div class="label">Requerimientos especiales</div>
        @php $requisitos = $detail['requerimientos_especiales'] ?? ['catalog' => [], 'otros' => []]; @endphp
        @if(empty($requisitos['catalog']) && empty($requisitos['otros']))
            <div class="value">Ninguno aplica</div>
        @else
            <div class="value">
                {{ implode(', ', array_merge(array_column($requisitos['catalog'], 'label'), $requisitos['otros'])) }}
            </div>
        @endif
    </div>

    <div class="section">
        <div class="label">Productos reclamados / afectados</div>
        @if(!empty($detail['productos']))
            <table class="print-table">
                <thead>
                    <tr><th>Marca</th><th>Referencia</th><th>Descripción</th><th>Cantidad</th></tr>
                </thead>
                <tbody>
                    @foreach($detail['productos'] as $producto)
                        <tr>
                            <td>{{ $producto['marca'] ?? '—' }}</td>
                            <td>{{ $producto['referencia'] ?? '—' }}</td>
                            <td>{{ $producto['descripcion'] ?? '—' }}</td>
                            <td>{{ $producto['cantidad_reclamada'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">No aplica para este tipo de ticket</p>
        @endif
    </div>

    <div class="section">
        <div class="label">Informe de inspección</div>
        <div class="value">{{ $detail['inspection_report_status'] === 'not_applicable' ? 'No aplica para este tipo de ticket' : ($detail['inspection_report_status'] === 'completed' ? 'Completado — ver documento de Informe de Inspección para el detalle y la firma del responsable.' : 'Aún no se ha realizado la inspección.') }}</div>
    </div>

    <div class="section">
        <div class="label">Observaciones</div>
        <div class="value">{{ $detail['observaciones'] ?: '—' }}</div>
    </div>

    <div class="section">
        <div class="label">Adjuntos</div>
        <div class="value">{{ !empty($detail['adjuntos']) ? implode(', ', $detail['adjuntos']) : 'Sin adjuntos' }}</div>
    </div>

    <div class="footer">
        Atlantic · Panamá · Nota: el diagnóstico y la firma del responsable se registran en el documento de Informe de Inspección, no en este ticket.
    </div>
</body>
</html>
