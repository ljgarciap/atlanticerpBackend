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
    .blank-line { border-bottom: 1px solid #2a2520; height: 26px; margin-bottom: 8px; }

    .empty-note { color: #9ca3af; font-style: italic; }

    .signatures { display: table; width: 100%; margin-top: 28px; }
    .signature-box { display: table-cell; width: 50%; text-align: center; padding-top: 40px; border-top: 1px solid #2a2520; }

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
        <div class="sub">Plantilla en blanco — Informe de Inspección — Ticket {{ $ticket->numero }} — {{ $tipoLabel }}{{ $subtipoLabel ? ' — '.$subtipoLabel : '' }}</div>
        <div class="sub">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</div>
    </div>

    <div class="section">
        <div class="label">Fecha de inspección</div>
        <div class="blank-line"></div>

        <div class="label">Horario (inicio – fin)</div>
        <div class="blank-line"></div>

        <div class="label">Técnico responsable</div>
        <div class="blank-line"></div>
    </div>

    <div class="section">
        <div class="label">Hallazgos de la inspección</div>
        @if(!empty($fields))
            @foreach($fields as $field)
                <div class="label">{{ $field['label'] }}</div>
                <div class="blank-line"></div>
            @endforeach
        @else
            <p class="empty-note">Sin campos definidos para este tipo de ticket</p>
        @endif
    </div>

    <div class="section">
        <div class="label">Materiales / insumos utilizados</div>
        <div class="blank-line"></div>
        <div class="blank-line"></div>
        <div class="blank-line"></div>
    </div>

    <div class="section">
        <div class="label">Conclusión</div>
        <div class="blank-line"></div>
        <div class="blank-line"></div>

        <div class="label">¿Requiere seguimiento? (Sí / No)</div>
        <div class="blank-line"></div>

        <div class="label">Próximos pasos</div>
        <div class="blank-line"></div>
    </div>

    <div class="signatures">
        <div class="signature-box">Técnico responsable</div>
        <div class="signature-box">Cliente</div>
    </div>

    <div class="footer">
        Illuminations · Panamá · Plantilla para completar en campo — el informe digital se guarda en el sistema.
    </div>
</body>
</html>
