<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2a2520; }
    .header { border-bottom: 3px solid #5BA5A0; padding-bottom: 10px; margin-bottom: 16px; }
    /* SCRUM-781 (punto 5) — mismo isólogo SVG inline ya usado en ventas-diseno/quote-pdf.blade.php
       (compatible con dompdf, sin depender de un asset externo/base64). */
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

    .signatures { display: table; width: 100%; margin-top: 20px; }
    .signature-box { display: table-cell; width: 50%; text-align: center; padding-top: 24px; border-top: 1px solid #2a2520; }
    .signature-box.signed { padding-top: 4px; }
    .signature-image { max-height: 50px; max-width: 90%; margin-bottom: 4px; }

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
        <div class="sub">Informe de Inspección {{ $report->numero }} — Ticket {{ $ticket->numero }} — {{ $tipoLabel }}{{ $subtipoLabel ? ' — '.$subtipoLabel : '' }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="label">Fecha de inspección</div>
        <div class="value">{{ $report->fecha_inspeccion?->format('d/m/Y') ?? '—' }}</div>

        <div class="label">Horario</div>
        <div class="value">
            @if($report->hora_inicio || $report->hora_fin)
                {{ $report->hora_inicio ?? '—' }}{{ $report->hora_fin ? ' – '.$report->hora_fin : '' }}
            @else
                —
            @endif
        </div>

        <div class="label">Técnico responsable</div>
        <div class="value">
            @if($report->internalTechnician)
                {{ $report->internalTechnician->first_name }} {{ $report->internalTechnician->last_name }}
            @else
                —
            @endif
        </div>
    </div>

    <div class="section">
        <div class="label">Hallazgos de la inspección</div>
        @if(!empty($report->findings))
            <table class="print-table">
                <thead>
                    <tr><th>Campo</th><th>Valor</th></tr>
                </thead>
                <tbody>
                    @foreach($report->findings as $finding)
                        <tr>
                            <td>{{ $finding['label'] ?? '—' }}</td>
                            <td>{{ $finding['value'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">Sin hallazgos registrados</p>
        @endif
    </div>

    <div class="section">
        <div class="label">Materiales / insumos utilizados</div>
        @if($report->materials->isNotEmpty())
            <table class="print-table">
                <thead>
                    <tr><th>Material</th><th>Cantidad</th></tr>
                </thead>
                <tbody>
                    @foreach($report->materials as $material)
                        <tr>
                            <td>{{ $material->nombre_material }}</td>
                            <td>{{ $material->cantidad }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">Ninguno registrado</p>
        @endif
    </div>

    <div class="section">
        <div class="label">Conclusión</div>
        <div class="value">{{ $report->conclusion ?: '—' }}</div>

        <div class="label">¿Requiere seguimiento?</div>
        <div class="value">{{ $report->requiere_seguimiento ? 'Sí' : 'No' }}</div>

        @if($report->requiere_seguimiento)
            <div class="label">Próximos pasos</div>
            <div class="value">{{ $report->proximos_pasos ?: '—' }}</div>
        @endif
    </div>

    <div class="signatures">
        <div class="signature-box">{{ $report->firma_tecnico_nombre ?: 'Sin firma' }}<br><span class="sub">Técnico responsable</span></div>
        <div class="signature-box {{ $firmaClienteImagenDataUri ? 'signed' : '' }}">
            @if($firmaClienteImagenDataUri)
                <img class="signature-image" src="{{ $firmaClienteImagenDataUri }}" alt="Firma del cliente">
                <br>
            @endif
            {{ $report->firma_cliente_nombre ?: 'Sin firma' }}<br><span class="sub">Cliente</span>
        </div>
    </div>

    <div class="footer">
        Atlantic · Panamá · Informe generado automáticamente desde el sistema.
    </div>
</body>
</html>
